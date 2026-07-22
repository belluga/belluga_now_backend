<?php

declare(strict_types=1);

namespace App\Application\Accounts;

use App\Application\AccountProfiles\AccountProfileLifecycleService;
use App\Application\AccountProfiles\AccountProfileOutboxDispatcher;
use App\Application\AccountProfiles\AccountProfileTransactionContext;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Laravel\Connection;
use RuntimeException;

class AccountManagementService
{
    public function __construct(
        private readonly AccountQueryService $accountQueryService,
        private readonly AccountOwnershipStateService $ownershipStateService,
        private readonly MapPoiProjectionService $mapPoiProjectionService,
        private readonly PushUserGatewayContract $pushUsers,
        private readonly AccountProfileLifecycleService $accountProfileLifecycleService,
        private readonly AccountProfileOutboxDispatcher $accountProfileOutboxDispatcher,
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
        $this->deleteInsideAccountAggregateDeletionBoundary($account, $commandId);
    }

    public function deleteRepairApprovedTestSeedAggregate(Account $account): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages([
                'account' => ['Repair-owned test-seed aggregate deletion is local-only.'],
            ]);
        }

        $this->forceDeleteInsideAccountAggregateDeletionBoundary($account);
    }

    public function restore(Account $account): Account
    {
        $account->restore();

        return $account->fresh();
    }

    public function forceDelete(Account $account, ?string $commandId = null): void
    {
        $this->assertUnmanagedAccountForDelete($account);
        $this->forceDeleteInsideAccountAggregateDeletionBoundary($account, $commandId);
    }

    private function deleteInsideAccountAggregateDeletionBoundary(Account $account, ?string $commandId = null): void
    {
        $tenantConnection = DB::connection('tenant');
        if (! $tenantConnection instanceof Connection) {
            throw new RuntimeException('A MongoDB tenant connection is required for Account aggregate deletion.');
        }

        $baseCommandId = $this->normalizeAggregateDeleteCommandId($account, $commandId, 'soft_delete');
        $outboxEventIds = [];
        $profileIds = [];

        $tenantConnection->transaction(function () use ($account, $tenantConnection, $baseCommandId, &$outboxEventIds, &$profileIds): void {
            $profileIds = $this->allAccountProfileIds($account);
            $context = $this->profileTransactionContext($tenantConnection);

            $outboxEventIds = $this->deleteProfilesInsideAccountAggregateDeletionBoundary(
                $account,
                $context,
                $baseCommandId,
            );
            $account->roleTemplates()->delete();
            $account->delete();
        });

        foreach ($outboxEventIds as $eventId) {
            $this->accountProfileOutboxDispatcher->dispatchEvent($eventId);
        }

        $this->deleteMapPoiProjections($profileIds);
    }

    private function forceDeleteInsideAccountAggregateDeletionBoundary(Account $account, ?string $commandId = null): void
    {
        $tenantConnection = DB::connection('tenant');
        if (! $tenantConnection instanceof Connection) {
            throw new RuntimeException('A MongoDB tenant connection is required for Account aggregate deletion.');
        }

        $baseCommandId = $this->normalizeAggregateDeleteCommandId($account, $commandId, 'force_delete');
        $outboxEventIds = [];
        $profileIds = [];

        $tenantConnection->transaction(function () use ($account, $tenantConnection, $baseCommandId, &$outboxEventIds, &$profileIds): void {
            $profileIds = $this->allAccountProfileIds($account);
            $context = $this->profileTransactionContext($tenantConnection);

            $outboxEventIds = $this->forceDeleteProfilesInsideAccountAggregateDeletionBoundary(
                $account,
                $context,
                $baseCommandId,
            );
            $account->roleTemplates()->withTrashed()->forceDelete();
            $account->forceDelete();
        });

        foreach ($outboxEventIds as $eventId) {
            $this->accountProfileOutboxDispatcher->dispatchEvent($eventId);
        }

        $this->deleteMapPoiProjections($profileIds);
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

    /**
     * @return array<int, string>
     */
    private function deleteProfilesInsideAccountAggregateDeletionBoundary(
        Account $account,
        AccountProfileTransactionContext $context,
        string $baseCommandId,
    ): array
    {
        return $this->deleteProfilesUsingLifecyclePath(
            AccountProfile::query()
                ->where('account_id', (string) $account->_id)
                ->orderBy('_id')
                ->get(),
            $context,
            $baseCommandId,
            false,
        );
    }

    /**
     * @return array<int, string>
     */
    private function forceDeleteProfilesInsideAccountAggregateDeletionBoundary(
        Account $account,
        AccountProfileTransactionContext $context,
        string $baseCommandId,
    ): array
    {
        return $this->deleteProfilesUsingLifecyclePath(
            AccountProfile::withTrashed()
                ->where('account_id', (string) $account->_id)
                ->orderBy('_id')
                ->get(),
            $context,
            $baseCommandId,
            true,
        );
    }

    /**
     * @param  iterable<int, AccountProfile>  $profiles
     * @return array<int, string>
     */
    private function deleteProfilesUsingLifecyclePath(
        iterable $profiles,
        AccountProfileTransactionContext $context,
        string $baseCommandId,
        bool $forceDelete,
    ): array {
        $profiles = array_values(array_filter(
            is_array($profiles) ? $profiles : iterator_to_array($profiles, false),
            static fn (mixed $profile): bool => $profile instanceof AccountProfile,
        ));
        $singleProfile = count($profiles) === 1;
        $eventIds = [];

        foreach ($profiles as $profile) {
            $profileId = trim((string) $profile->getKey());
            if ($profileId === '') {
                continue;
            }

            $profileCommandId = $singleProfile
                ? $baseCommandId
                : "{$baseCommandId}:profile:{$profileId}";

            $profileEventIds = $forceDelete
                ? $this->accountProfileLifecycleService->forceDeleteWithinTransaction(
                    $profile,
                    $context,
                    $profileCommandId,
                    false,
                )
                : $this->accountProfileLifecycleService->deleteWithinTransaction(
                    $profile,
                    $context,
                    $profileCommandId,
                    false,
                );

            foreach ($profileEventIds as $eventId) {
                $eventId = trim((string) $eventId);
                if ($eventId !== '') {
                    $eventIds[] = $eventId;
                }
            }
        }

        return array_values(array_unique($eventIds));
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
     * @return array<int, string>
     */
    private function allAccountProfileIds(Account $account): array
    {
        return AccountProfile::query()
            ->withTrashed()
            ->where('account_id', (string) $account->_id)
            ->get(['_id'])
            ->map(static fn (AccountProfile $profile): string => trim((string) $profile->_id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    private function profileTransactionContext(Connection $connection): AccountProfileTransactionContext
    {
        $session = $connection->getSession();
        if ($session === null) {
            throw new RuntimeException('Account Profile transaction session is unavailable.');
        }

        return new AccountProfileTransactionContext(
            $connection->getDatabase(),
            $session,
        );
    }

    private function normalizeAggregateDeleteCommandId(Account $account, ?string $commandId, string $operation): string
    {
        $commandId = trim((string) $commandId);
        if ($commandId !== '') {
            return $commandId;
        }

        return sprintf(
            'account-%s-%s-%s',
            $operation,
            trim((string) $account->getKey()),
            bin2hex(random_bytes(8)),
        );
    }

    /**
     * @param  array<int, string>  $profileIds
     */
    private function deleteMapPoiProjections(array $profileIds): bool
    {
        $this->mapPoiProjectionService->deleteByRefs('account_profile', $profileIds);

        return true;
    }
}
