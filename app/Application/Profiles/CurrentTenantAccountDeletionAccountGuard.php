<?php

declare(strict_types=1);

namespace App\Application\Profiles;

use App\Application\AccountProfiles\AccountProfileDeletionTerminalization;
use App\Application\AccountProfiles\AccountProfileLifecycleService;
use App\Application\AccountProfiles\AccountProfileOutboxDispatcher;
use App\Application\AccountProfiles\AccountProfileTransactionContext;
use App\Application\AccountProfiles\AccountProfileTransactionRunner;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use RuntimeException;
use Throwable;

/**
 * Revalidates the personal-account graph in the same MongoDB transaction that
 * destroys it. The caller's snapshot is an upper bound, never authority for a
 * later hard delete.
 */
final class CurrentTenantAccountDeletionAccountGuard
{
    public function __construct(
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileLifecycleService $profileLifecycle,
        private readonly AccountProfileOutboxDispatcher $outboxDispatcher,
    ) {}

    /**
     * @param  array<int, string>  $candidateProfileIds
     * @param  array<int, string>  $candidateAccountIds
     */
    public function eraseRevalidatedPersonalGraph(
        string $userId,
        array $candidateProfileIds,
        array $candidateAccountIds,
        array $attempt,
    ): void {
        if ($candidateProfileIds === []) {
            return;
        }

        try {
            $profileCommandIds = [];
            /** @var list<string> $eventIds */
            $eventIds = $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use ($userId, $candidateProfileIds, $candidateAccountIds, $attempt, &$profileCommandIds): array {
                $profiles = AccountProfile::withTrashed()
                    ->whereIn('_id', $candidateProfileIds)
                    ->where('created_by', $userId)
                    ->where('created_by_type', 'tenant')
                    ->where('profile_type', 'personal')
                    ->orderBy('_id')
                    ->get();

                $profileIds = $this->normalizedStrings($profiles->map(
                    static fn (AccountProfile $profile): string => (string) $profile->getKey(),
                )->all());
                $profileIdsByAccount = [];
                foreach ($profiles as $profile) {
                    $accountId = trim((string) $profile->account_id);
                    $profileId = trim((string) $profile->getKey());
                    if ($accountId !== '' && $profileId !== '') {
                        $profileIdsByAccount[$accountId][] = $profileId;
                    }
                }

                $accountIds = $this->revalidatedDeletableAccountIds(
                    $userId,
                    $candidateAccountIds,
                    $profileIds,
                    $profileIdsByAccount,
                );

                $eventIds = [];
                foreach ($profiles as $profile) {
                    $profileId = (string) $profile->getKey();
                    $commandId = "current-account-delete:{$userId}:{$profileId}:force_delete";
                    $profileCommandIds[] = $commandId;
                    $profileEventIds = $this->profileLifecycle->forceDeleteWithinTransaction(
                        $profile,
                        $context,
                        $commandId,
                        false,
                        $this->terminalizationForProfile($attempt, $profile),
                    );
                    $eventIds = [...$eventIds, ...$profileEventIds];
                }

                if ($accountIds !== []) {
                    AccountRoleTemplate::withTrashed()->whereIn('account_id', $accountIds)->forceDelete();
                    Account::withoutEvents(static function () use ($accountIds, $userId): void {
                        Account::withTrashed()
                            ->whereIn('_id', $accountIds)
                            ->where('created_by', $userId)
                            ->where('created_by_type', 'tenant')
                            ->where('ownership_state', 'unmanaged')
                            ->forceDelete();
                    });
                }

                $this->releaseRetainedAccountGates(
                    $candidateAccountIds,
                    $accountIds,
                    $attempt,
                );

                return array_values(array_unique($eventIds));
            }, function () use (&$profileCommandIds): ?array {
                if ($profileCommandIds === []) {
                    return null;
                }

                $eventIds = [];
                foreach ($profileCommandIds as $commandId) {
                    $receipt = $this->profileLifecycle->committedReceipt($commandId);
                    if ($receipt === null) {
                        return null;
                    }
                    $eventId = trim((string) ($receipt['outbox_event_id'] ?? ''));
                    if ($eventId !== '') {
                        $eventIds[] = $eventId;
                    }
                }

                return array_values(array_unique($eventIds));
            });

            foreach ($eventIds as $eventId) {
                $this->outboxDispatcher->dispatchEvent($eventId);
            }
        } catch (Throwable $throwable) {
            if ($this->isTransactionSupportError($throwable)) {
                throw new RuntimeException(
                    'Tenant MongoDB transaction support is required for current-account deletion. Configure a replica-set transaction-capable runtime.',
                    0,
                    $throwable,
                );
            }

            throw $throwable;
        }
    }

    /**
     * @param  array<int, string>  $candidateAccountIds
     * @param  array<int, string>  $candidateProfileIds
     * @param  array<string, array<int, string>>  $candidateProfileIdsByAccount
     * @return array<int, string>
     */
    private function revalidatedDeletableAccountIds(
        string $userId,
        array $candidateAccountIds,
        array $candidateProfileIds,
        array $candidateProfileIdsByAccount,
    ): array {
        $candidateAccountIds = $this->normalizedStrings($candidateAccountIds);
        if ($candidateAccountIds === []) {
            return [];
        }

        $ownedAccountIds = Account::query()
            ->whereIn('_id', $candidateAccountIds)
            ->where('created_by', $userId)
            ->where('created_by_type', 'tenant')
            ->where('ownership_state', 'unmanaged')
            ->pluck('id')
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        if ($ownedAccountIds === []) {
            return [];
        }

        $liveProfileIdsByAccount = [];
        AccountProfile::query()
            ->whereIn('account_id', $ownedAccountIds)
            ->whereNull('deleted_at')
            ->orderBy('_id')
            ->get(['_id', 'account_id'])
            ->each(function (AccountProfile $profile) use (&$liveProfileIdsByAccount): void {
                $accountId = trim((string) $profile->account_id);
                $profileId = trim((string) $profile->getKey());
                if ($accountId !== '' && $profileId !== '') {
                    $liveProfileIdsByAccount[$accountId][] = $profileId;
                }
            });

        $memberIdsByAccount = [];
        \App\Models\Tenants\AccountUser::query()
            ->whereIn('account_roles.account_id', $ownedAccountIds)
            ->orderBy('_id')
            ->get(['_id', 'account_roles'])
            ->each(function (\App\Models\Tenants\AccountUser $member) use (&$memberIdsByAccount, $ownedAccountIds): void {
                $memberId = trim((string) $member->getKey());
                if ($memberId === '') {
                    return;
                }

                foreach ((array) ($member->account_roles ?? []) as $role) {
                    $accountId = trim((string) (is_array($role) ? ($role['account_id'] ?? '') : ''));
                    if ($accountId !== '' && in_array($accountId, $ownedAccountIds, true)) {
                        $memberIdsByAccount[$accountId][] = $memberId;
                    }
                }
            });

        return $this->normalizedStrings(array_filter(
            $ownedAccountIds,
            function (string $accountId) use ($candidateProfileIds, $candidateProfileIdsByAccount, $liveProfileIdsByAccount, $memberIdsByAccount, $userId): bool {
                $snapshotProfileIds = $this->normalizedStrings($candidateProfileIdsByAccount[$accountId] ?? []);
                $liveProfileIds = $this->normalizedStrings($liveProfileIdsByAccount[$accountId] ?? []);
                $memberIds = $this->normalizedStrings($memberIdsByAccount[$accountId] ?? []);

                return $snapshotProfileIds !== []
                    && count(array_diff($snapshotProfileIds, $candidateProfileIds)) === 0
                    && $liveProfileIds !== []
                    && count(array_diff($liveProfileIds, $snapshotProfileIds)) === 0
                    && ($memberIds === [] || (count($memberIds) === 1 && hash_equals($userId, $memberIds[0])));
            },
        ));
    }

    /**
     * @param  array<int, string>  $candidateAccountIds
     * @param  array<int, string>  $terminalizedAccountIds
     * @param  array<string, mixed>  $attempt
     */
    private function releaseRetainedAccountGates(
        array $candidateAccountIds,
        array $terminalizedAccountIds,
        array $attempt,
    ): void {
        $attemptId = trim((string) ($attempt['_id'] ?? ''));
        $attemptGeneration = (int) ($attempt['attempt_generation'] ?? 0);
        $candidateAccountIds = $this->normalizedStrings($candidateAccountIds);
        $terminalizedAccountIds = $this->normalizedStrings($terminalizedAccountIds);
        $retainedAccountIds = array_values(array_diff($candidateAccountIds, $terminalizedAccountIds));
        if ($attemptId === '' || $attemptGeneration < 1 || $retainedAccountIds === []) {
            return;
        }

        Account::query()
            ->whereIn('_id', $retainedAccountIds)
            ->where('account_profile_deletion_gate.attempt_id', $attemptId)
            ->where('account_profile_deletion_gate.attempt_generation', $attemptGeneration)
            ->update(['account_profile_deletion_gate' => null]);
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private function normalizedStrings(array $values): array
    {
        return collect($values)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function isTransactionSupportError(Throwable $throwable): bool
    {
        $message = strtolower($throwable->getMessage());

        return str_contains($message, 'transaction numbers are only allowed')
            || str_contains($message, 'transactions are not supported')
            || str_contains($message, 'replica set')
            || str_contains($message, 'mongos')
            || str_contains($message, 'starttransaction');
    }

    /** @param array<string, mixed> $attempt */
    private function terminalizationForProfile(
        array $attempt,
        AccountProfile $profile,
    ): AccountProfileDeletionTerminalization {
        $attemptId = trim((string) ($attempt['_id'] ?? ''));
        $attemptGeneration = (int) ($attempt['attempt_generation'] ?? 0);
        $claimToken = trim((string) ($attempt['claim_token'] ?? ''));
        $profileId = trim((string) $profile->getKey());
        if ($attemptId === '' || $attemptGeneration < 1 || $claimToken === '' || $profileId === '') {
            throw new \LogicException('Account Profile deletion terminalization authorization is invalid.');
        }

        foreach ((array) ($attempt['profile_descriptors'] ?? []) as $descriptor) {
            if (! is_array($descriptor) || ! hash_equals($profileId, trim((string) ($descriptor['profile_id'] ?? '')))) {
                continue;
            }

            return new AccountProfileDeletionTerminalization(
                $attemptId,
                $attemptGeneration,
                $claimToken,
                trim((string) ($descriptor['account_id'] ?? '')),
                (int) ($descriptor['lifecycle_fence_revision'] ?? -1),
            );
        }

        throw new \LogicException('Account Profile deletion target is absent from the frozen attempt descriptor.');
    }
}
