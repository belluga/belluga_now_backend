<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\AccountProfiles\AccountProfileLifecycleService;
use App\Application\AccountProfiles\AccountProfileOutboxDispatcher;
use App\Application\AccountProfiles\AccountProfileTransactionContext;
use App\Application\AccountProfiles\AccountProfileTransactionRunner;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class AccountManagementService
{
    public function __construct(
        private readonly AccountQueryService $accountQueryService,
        private readonly AccountOwnershipStateService $ownershipStateService,
        private readonly PushUserGatewayContract $pushUsers,
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileLifecycleService $profileLifecycle,
        private readonly AccountProfileOutboxDispatcher $outboxDispatcher,
    ) {}

    public function paginateForUser(
        AccountUser|LandlordUser $user,
        bool $includeArchived,
        int $perPage = 15,
        array $queryParams = []
    ): LengthAwarePaginator {
        return $this->accountQueryService->paginateForUser(
            $user,
            $queryParams,
            $includeArchived,
            $perPage
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{account: Account, role: AccountRoleTemplate}
     */
    public function create(array $payload): array
    {
        try {
            return DB::connection('tenant')->transaction(
                fn (): array => $this->createWithinCurrentTransaction($payload)
            );
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'account' => ['Account already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'account' => ['Something went wrong when trying to create the account.'],
            ]);
        }
    }

    /**
     * Create account + default admin role in the current tenant transaction boundary.
     *
     * @param  array<string, mixed>  $payload
     * @return array{account: Account, role: AccountRoleTemplate}
     */
    public function createWithinCurrentTransaction(array $payload): array
    {
        $ownershipIntent = $this->resolveOwnershipIntent($payload);
        $payload = $this->applyOwnershipIntent($payload, $ownershipIntent);
        $account = Account::create($payload);

        $role = $account->roleTemplates()->create([
            'name' => 'Admin',
            'description' => 'Administrador',
            'permissions' => ['*'],
        ]);

        return [
            'account' => $account->fresh(),
            'role' => $role->fresh(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveOwnershipIntent(array $payload): string
    {
        $rawValue = $payload['ownership_state'] ?? null;
        $intent = is_string($rawValue)
            ? $this->ownershipStateService->normalize($rawValue)
            : null;

        if (
            $intent === null ||
            ! in_array($intent, AccountOwnershipStateService::allowedCreateIntents(), true)
        ) {
            throw ValidationException::withMessages([
                'ownership_state' => [
                    'ownership_state must be tenant_owned or unmanaged.',
                ],
            ]);
        }

        return $intent;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyOwnershipIntent(array $payload, string $intent): array
    {
        unset($payload['ownership_state']);

        $payload['ownership_state'] = $intent;

        if ($intent === AccountOwnershipStateService::UNMANAGED) {
            unset($payload['organization_id']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Account $account, array $attributes): Account
    {
        if (array_key_exists('ownership_state', $attributes)) {
            $normalizedOwnershipState = $this->ownershipStateService->normalize(
                is_string($attributes['ownership_state'])
                    ? $attributes['ownership_state']
                    : null
            );
            if (
                $normalizedOwnershipState === null ||
                ! in_array($normalizedOwnershipState, AccountOwnershipStateService::allowedCreateIntents(), true)
            ) {
                throw ValidationException::withMessages([
                    'ownership_state' => ['ownership_state must be tenant_owned or unmanaged.'],
                ]);
            }
            $attributes['ownership_state'] = $normalizedOwnershipState;
            if ($normalizedOwnershipState === AccountOwnershipStateService::UNMANAGED) {
                $attributes['organization_id'] = null;
            }
        }

        try {
            $account->fill($attributes);
            $account->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'slug' => ['Account slug already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'account' => ['Something went wrong when trying to update the account.'],
            ]);
        }

        return $account->fresh();
    }

    public function delete(Account $account, ?string $commandId = null): void
    {
        $this->assertUnmanagedAccountForDelete($account);
        $this->deleteInsideAccountAggregateDeletionBoundary($account, $this->commandId($commandId));
    }

    public function deleteRepairApprovedTestSeedAggregate(Account $account): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages([
                'account' => ['Repair-owned test-seed aggregate deletion is local-only.'],
            ]);
        }

        $this->forceDeleteInsideAccountAggregateDeletionBoundary($account, $this->commandId(null));
    }

    public function restore(Account $account): Account
    {
        $account->restore();

        return $account->fresh();
    }

    public function forceDelete(Account $account, ?string $commandId = null): void
    {
        $this->assertUnmanagedAccountForDelete($account);
        $this->forceDeleteInsideAccountAggregateDeletionBoundary($account, $this->commandId($commandId));
    }

    private function deleteInsideAccountAggregateDeletionBoundary(Account $account, string $commandId): void
    {
        $accountId = (string) $account->getKey();
        $profileCommandIds = [];

        /** @var list<string> $eventIds */
        $eventIds = $this->transactionRunner->run(
            function (AccountProfileTransactionContext $context) use ($accountId, $commandId, &$profileCommandIds): array {
                $persistedAccount = Account::query()->findOrFail($accountId);
                $eventIds = $this->cascadeProfilesWithinTransaction(
                    $persistedAccount,
                    $context,
                    $commandId,
                    false,
                    $profileCommandIds,
                );
                $persistedAccount->roleTemplates()->delete();
                $persistedAccount->delete();

                return $eventIds;
            },
            fn (): ?array => $this->reconcileCascadeCommand($profileCommandIds),
        );

        $this->dispatchOutboxEvents($eventIds);
    }

    private function forceDeleteInsideAccountAggregateDeletionBoundary(Account $account, string $commandId): void
    {
        $accountId = (string) $account->getKey();
        $profileCommandIds = [];

        /** @var list<string> $eventIds */
        $eventIds = $this->transactionRunner->run(
            function (AccountProfileTransactionContext $context) use ($accountId, $commandId, &$profileCommandIds): array {
                $persistedAccount = Account::withTrashed()->findOrFail($accountId);
                $eventIds = $this->cascadeProfilesWithinTransaction(
                    $persistedAccount,
                    $context,
                    $commandId,
                    true,
                    $profileCommandIds,
                );
                $persistedAccount->roleTemplates()->withTrashed()->forceDelete();
                $persistedAccount->forceDelete();

                return $eventIds;
            },
            fn (): ?array => $this->reconcileCascadeCommand($profileCommandIds),
        );

        $this->dispatchOutboxEvents($eventIds);
    }

    private function assertUnmanagedAccountForDelete(Account $account): void
    {
        $ownershipState = $this->ownershipStateService->deriveOwnershipState($account);
        if ($ownershipState === AccountOwnershipStateService::UNMANAGED) {
            return;
        }

        throw ValidationException::withMessages([
            'account' => ['Only unmanaged accounts can be deleted.'],
        ]);
    }

    public function attachUser(Account $account, AccountUser $user, AccountRoleTemplate $role): void
    {
        DB::connection('tenant')->transaction(function () use ($account, $user, $role): void {
            $this->attachUserWithinCurrentTransaction($account, $user, $role);
        });
    }

    public function attachUserWithinCurrentTransaction(Account $account, AccountUser $user, AccountRoleTemplate $role): void
    {
        $user->accountRoles()->create([
            ...$role->attributesToArray(),
            'account_id' => $account->id,
        ]);

        $this->pushUsers->syncPushDeviceAccountIds(
            (string) $user->_id,
            $user->fresh()->getAccessToIds(),
        );
    }

    public function detachUser(Account $account, AccountUser $user, AccountRoleTemplate $role): void
    {
        $deactivateUserId = null;
        $syncUserId = null;
        $syncAccessIds = [];

        DB::connection('tenant')->transaction(function () use ($account, $user, $role, &$deactivateUserId, &$syncUserId, &$syncAccessIds): void {
            $embeddedRole = $user->accountRoles()
                ->where('slug', $role->slug)
                ->where('account_id', $account->id)
                ->first();

            if ($embeddedRole) {
                $embeddedRole->delete();
                $user->save();

                $remainingAccessIds = $user->getAccessToIds();
                if ($remainingAccessIds === []) {
                    $deactivateUserId = (string) $user->_id;

                    return;
                }

                $syncUserId = (string) $user->_id;
                $syncAccessIds = $remainingAccessIds;
            }
        });

        if (is_string($deactivateUserId) && $deactivateUserId !== '') {
            $this->pushUsers->deactivatePushDevicesForUser($deactivateUserId);

            return;
        }

        if (is_string($syncUserId) && $syncUserId !== '') {
            $this->pushUsers->syncPushDeviceAccountIds($syncUserId, $syncAccessIds);
        }
    }

    /**
     * @param  array<int, string>  $profileCommandIds
     * @return list<string>
     */
    private function cascadeProfilesWithinTransaction(
        Account $account,
        AccountProfileTransactionContext $context,
        string $accountCommandId,
        bool $forceDelete,
        array &$profileCommandIds,
    ): array {
        $operation = $forceDelete ? 'force_delete' : 'soft_delete';
        $eventIds = [];
        $profiles = AccountProfile::withTrashed()
            ->where('account_id', (string) $account->getKey())
            ->orderBy('_id')
            ->get();
        $profileIds = $profiles
            ->map(static fn (AccountProfile $profile): string => (string) $profile->getKey())
            ->filter(static fn (string $profileId): bool => trim($profileId) !== '')
            ->values()
            ->all();
        $eventIds = $this->profileLifecycle->cleanSurvivingReferencesWithinTransaction(
            $context,
            $accountCommandId,
            $profileIds,
        );

        foreach ($profiles as $profile) {
            $profileId = (string) $profile->getKey();
            $profileCommandId = "{$accountCommandId}:profile:{$profileId}:{$operation}";
            $profileCommandIds[] = $profileCommandId;
            $profileEventIds = $forceDelete
                ? $this->profileLifecycle->forceDeleteWithinTransaction(
                    $profile,
                    $context,
                    $profileCommandId,
                    false,
                    cleanSurvivingReferences: false,
                )
                : $this->profileLifecycle->deleteWithinTransaction(
                    $profile,
                    $context,
                    $profileCommandId,
                    false,
                    cleanSurvivingReferences: false,
                );
            $eventIds = [...$eventIds, ...$profileEventIds];
        }

        return array_values(array_unique($eventIds));
    }

    /**
     * @param  array<int, string>  $profileCommandIds
     * @return list<string>|null
     */
    private function reconcileCascadeCommand(array $profileCommandIds): ?array
    {
        if ($profileCommandIds === []) {
            return null;
        }

        $eventIds = [];
        foreach ($profileCommandIds as $profileCommandId) {
            $receipt = $this->profileLifecycle->committedReceipt($profileCommandId);
            if ($receipt === null) {
                return null;
            }
            $eventId = trim((string) ($receipt['outbox_event_id'] ?? ''));
            if ($eventId !== '') {
                $eventIds[] = $eventId;
            }
        }

        return array_values(array_unique($eventIds));
    }

    /** @param list<string> $eventIds */
    private function dispatchOutboxEvents(array $eventIds): void
    {
        foreach ($eventIds as $eventId) {
            $this->outboxDispatcher->dispatchEvent($eventId);
        }
    }

    private function commandId(?string $commandId): string
    {
        $commandId = trim((string) $commandId);

        return $commandId === '' ? (string) Str::uuid() : $commandId;
    }
}
