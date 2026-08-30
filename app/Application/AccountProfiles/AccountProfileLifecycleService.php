<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountProfileLifecycleService
{
    private const LAST_PROFILE_ERROR_KEY = 'account_profile_lifecycle';

    private const LAST_PROFILE_ERROR_MESSAGE = 'A live account must keep at least one active account profile. Delete the account aggregate instead.';

    public function __construct(
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileOutboxPublisher $outboxPublisher,
        private readonly AccountProfileOutboxDispatcher $outboxDispatcher,
        private readonly AccountProfileMutationGate $mutationGate,
        private readonly AccountProfileReferenceCleanupService $referenceCleanup,
    ) {}

    public function delete(AccountProfile $profile, ?string $commandId = null): void
    {
        $profileId = (string) $profile->getKey();
        $commandId = $this->commandId($commandId);
        $fingerprint = $this->outboxPublisher->fingerprintForLifecycle($profileId, 'soft_delete');

        /** @var list<string> $eventIds */
        $eventIds = $this->transactionRunner->run(
            fn (AccountProfileTransactionContext $context): array => $this->deleteWithinTransaction(
                $profile,
                $context,
                $commandId,
            ),
            function () use ($commandId, $fingerprint): ?array {
                $receipt = $this->outboxPublisher->committedReceipt($commandId);
                if ($receipt === null) {
                    return null;
                }

                $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);

                $eventId = $this->outboxEventId($receipt);

                return $eventId === null ? [] : [$eventId];
            },
        );

        foreach ($eventIds as $eventId) {
            $this->outboxDispatcher->dispatchEvent($eventId);
        }
    }

    public function restore(AccountProfile $profile, ?string $commandId = null): AccountProfile
    {
        $profileId = (string) $profile->getKey();
        $commandId = $this->commandId($commandId);
        $fingerprint = $this->outboxPublisher->fingerprintForLifecycle($profileId, 'restore');

        /** @var array{profile:AccountProfile,event_id:?string} $result */
        $result = $this->transactionRunner->run(
            function (AccountProfileTransactionContext $context) use ($profileId, $commandId, $fingerprint): array {
                $receipt = $this->outboxPublisher->receipt($context, $commandId);
                if ($receipt !== null) {
                    $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);

                    return $this->resultForReceipt($receipt);
                }

                $persistedProfile = AccountProfile::withTrashed()->findOrFail($profileId);
                $this->assertProfileMutationAllowed($persistedProfile, $context);
                $persistedProfile->restore();
                $persistedProfile->setAttribute(
                    'aggregate_revision',
                    max(0, (int) $persistedProfile->getAttribute('aggregate_revision')) + 1,
                );
                $persistedProfile->save();

                $persistedProfile = AccountProfile::query()->findOrFail($profileId);
                $eventId = $this->outboxPublisher->recordUpsert(
                    $context,
                    $persistedProfile,
                    $commandId,
                    $fingerprint,
                );

                return [
                    'profile' => $persistedProfile,
                    'event_id' => $eventId,
                ];
            },
            function () use ($commandId, $fingerprint): ?array {
                $receipt = $this->outboxPublisher->committedReceipt($commandId);
                if ($receipt === null) {
                    return null;
                }

                $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);

                return $this->resultForReceipt($receipt);
            },
        );

        if ($result['event_id'] !== null) {
            $this->outboxDispatcher->dispatchEvent($result['event_id']);
        }

        return $result['profile'];
    }

    public function deleteWithinTransaction(
        AccountProfile $profile,
        AccountProfileTransactionContext $context,
        string $commandId,
        bool $enforceLastProfileInvariant = true,
        ?AccountProfileDeletionTerminalization $terminalization = null,
        bool $cleanSurvivingReferences = true,
    ): array {
        $profileId = (string) $profile->getKey();
        $fingerprint = $this->outboxPublisher->fingerprintForLifecycle($profileId, 'soft_delete');
        $receipt = $this->outboxPublisher->receipt($context, $commandId);
        if ($receipt !== null) {
            $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);

            $eventId = $this->outboxEventId($receipt);

            return $eventId === null ? [] : [$eventId];
        }

        $persistedProfile = AccountProfile::withTrashed()->findOrFail($profileId);
        $this->assertLifecycleMutationAllowed($persistedProfile, $context, $terminalization);
        if ($enforceLastProfileInvariant && $persistedProfile->deleted_at === null) {
            $this->assertProfileMayBeSoftDeleted($persistedProfile);
        }
        $eventIds = $this->cleanReferencesBeforeDeletion(
            $context,
            $commandId,
            $profileId,
            $terminalization,
            $cleanSurvivingReferences,
        );
        if ($persistedProfile->deleted_at === null) {
            $persistedProfile->setAttribute(
                'aggregate_revision',
                max(0, (int) $persistedProfile->getAttribute('aggregate_revision')) + 1,
            );
            $persistedProfile->save();
            $persistedProfile->delete();
        }

        $eventIds[] = $this->outboxPublisher->recordTombstone(
            $context,
            $persistedProfile,
            $commandId,
            $fingerprint,
        );

        return array_values(array_unique($eventIds));
    }

    public function forceDelete(AccountProfile $profile, ?string $commandId = null): void
    {
        $profileId = (string) $profile->getKey();
        $commandId = $this->commandId($commandId);
        $fingerprint = $this->outboxPublisher->fingerprintForLifecycle($profileId, 'force_delete');

        /** @var list<string> $eventIds */
        $eventIds = $this->transactionRunner->run(
            fn (AccountProfileTransactionContext $context): array => $this->forceDeleteWithinTransaction(
                $profile,
                $context,
                $commandId,
            ),
            function () use ($commandId, $fingerprint): ?array {
                $receipt = $this->outboxPublisher->committedReceipt($commandId);
                if ($receipt === null) {
                    return null;
                }

                $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);

                $eventId = $this->outboxEventId($receipt);

                return $eventId === null ? [] : [$eventId];
            },
        );

        foreach ($eventIds as $eventId) {
            $this->outboxDispatcher->dispatchEvent($eventId);
        }
    }

    public function forceDeleteWithinTransaction(
        AccountProfile $profile,
        AccountProfileTransactionContext $context,
        string $commandId,
        bool $enforceLastProfileInvariant = true,
        ?AccountProfileDeletionTerminalization $terminalization = null,
        bool $cleanSurvivingReferences = true,
    ): array {
        $profileId = (string) $profile->getKey();
        $fingerprint = $this->outboxPublisher->fingerprintForLifecycle($profileId, 'force_delete');
        $receipt = $this->outboxPublisher->receipt($context, $commandId);
        if ($receipt !== null) {
            $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);

            $eventId = $this->outboxEventId($receipt);

            return $eventId === null ? [] : [$eventId];
        }

        $persistedProfile = AccountProfile::withTrashed()->findOrFail($profileId);
        $this->assertLifecycleMutationAllowed($persistedProfile, $context, $terminalization);
        if ($enforceLastProfileInvariant) {
            $this->assertProfileMayBeForceDeleted($persistedProfile);
        }
        $eventIds = $this->cleanReferencesBeforeDeletion(
            $context,
            $commandId,
            $profileId,
            $terminalization,
            $cleanSurvivingReferences,
        );
        $eventIds[] = $this->outboxPublisher->recordTombstone(
            $context,
            $persistedProfile,
            $commandId,
            $fingerprint,
        );
        $persistedProfile->forceDelete();

        return array_values(array_unique($eventIds));
    }

    /** @return array<string, mixed>|null */
    public function committedReceipt(string $commandId): ?array
    {
        return $this->outboxPublisher->committedReceipt($commandId);
    }

    public function assertAccountMutationAllowed(string $accountId): void
    {
        $this->mutationGate->assertAccountMutationAllowed($accountId);
    }

    public function assertProfileMutationAllowed(
        AccountProfile $profile,
        ?AccountProfileTransactionContext $context = null,
    ): void {
        $this->mutationGate->assertProfileMutationAllowed($profile, $context);
    }

    /** @param array<string, mixed> $payload */
    public function assertProfileCreationAllowed(
        array $payload,
        AccountProfileTransactionContext $context,
    ): void {
        $this->mutationGate->assertProfileCreationAllowed($payload, $context);
    }

    private function assertLifecycleMutationAllowed(
        AccountProfile $profile,
        AccountProfileTransactionContext $context,
        ?AccountProfileDeletionTerminalization $terminalization,
    ): void {
        if ($terminalization === null) {
            $this->assertProfileMutationAllowed($profile, $context);

            return;
        }

        if (
            ! hash_equals(
                $terminalization->attemptId,
                trim((string) $profile->getAttribute('account_profile_deletion_attempt_id')),
            )
            || $terminalization->lifecycleFenceRevision !== (int) $profile->getAttribute('lifecycle_fence_revision')
        ) {
            throw new ConcurrencyConflictException('Account Profile deletion target fence was changed.');
        }

        $profileAccountId = trim((string) $profile->account_id);
        if (! hash_equals($terminalization->accountId, $profileAccountId)) {
            throw new ConcurrencyConflictException('Account Profile deletion target account was changed.');
        }

        $attempt = $context->collection(AccountProfileMutationGate::DELETION_ATTEMPTS_COLLECTION)->findOne(
            [
                '_id' => $terminalization->attemptId,
                'attempt_generation' => $terminalization->attemptGeneration,
                'claim_token' => $terminalization->claimToken,
                'phase' => 'references_cleaned',
            ],
            $context->rawOptions(),
        );
        if ($attempt === null) {
            throw new ConcurrencyConflictException('Account Profile deletion attempt claim was lost.');
        }

        if ($profileAccountId === '') {
            return;
        }

        $account = Account::withTrashed()->find($profileAccountId);
        $gate = $account instanceof Account ? $this->mutationGate->accountDeletionGate($account) : null;
        if (
            $gate === null
            || ! hash_equals($terminalization->attemptId, (string) ($gate['attempt_id'] ?? ''))
            || $terminalization->attemptGeneration !== (int) ($gate['attempt_generation'] ?? 0)
        ) {
            throw new ConcurrencyConflictException('Account Profile deletion gate ownership was lost.');
        }
    }

    /** @return list<string> */
    public function cleanSurvivingReferencesWithinTransaction(
        AccountProfileTransactionContext $context,
        string $operationCommandId,
        array $deletedProfileIds,
    ): array {
        return $this->referenceCleanup->cleanSurvivingReferencesWithinTransaction(
            $context,
            $operationCommandId,
            $deletedProfileIds,
        );
    }

    /** @return list<string> */
    private function cleanReferencesBeforeDeletion(
        AccountProfileTransactionContext $context,
        string $commandId,
        string $profileId,
        ?AccountProfileDeletionTerminalization $terminalization,
        bool $cleanSurvivingReferences,
    ): array {
        if (! $cleanSurvivingReferences || $terminalization !== null) {
            return [];
        }

        return $this->cleanSurvivingReferencesWithinTransaction($context, $commandId, [$profileId]);
    }

    private function assertProfileMayBeSoftDeleted(AccountProfile $profile): void
    {
        if (! (bool) $profile->is_active) {
            return;
        }

        $accountId = trim((string) $profile->account_id);
        $account = $accountId === '' ? null : Account::query()->find($accountId);
        if (! $account instanceof Account) {
            return;
        }

        $activeProfiles = AccountProfile::query()
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->count();
        if ($activeProfiles > 1) {
            return;
        }

        throw ValidationException::withMessages([
            self::LAST_PROFILE_ERROR_KEY => [self::LAST_PROFILE_ERROR_MESSAGE],
        ]);
    }

    private function assertProfileMayBeForceDeleted(AccountProfile $profile): void
    {
        $accountId = trim((string) $profile->account_id);
        $account = $accountId === '' ? null : Account::query()->find($accountId);
        if (! $account instanceof Account) {
            return;
        }

        $activeProfiles = AccountProfile::query()
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->count();
        if ((bool) $profile->is_active && $profile->deleted_at === null && $activeProfiles <= 1) {
            throw ValidationException::withMessages([
                self::LAST_PROFILE_ERROR_KEY => [self::LAST_PROFILE_ERROR_MESSAGE],
            ]);
        }

        $restorableProfiles = AccountProfile::onlyTrashed()
            ->where('account_id', $accountId)
            ->count();
        if ($profile->deleted_at !== null && $activeProfiles === 0 && $restorableProfiles <= 1) {
            throw ValidationException::withMessages([
                self::LAST_PROFILE_ERROR_KEY => [self::LAST_PROFILE_ERROR_MESSAGE],
            ]);
        }
    }

    /** @param array<string, mixed> $receipt */
    private function outboxEventId(array $receipt): ?string
    {
        $eventId = trim((string) ($receipt['outbox_event_id'] ?? ''));

        return $eventId === '' ? null : $eventId;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array{profile:AccountProfile,event_id:?string}
     */
    private function resultForReceipt(array $receipt): array
    {
        return [
            'profile' => AccountProfile::withTrashed()->findOrFail((string) $receipt['profile_id']),
            'event_id' => $this->outboxEventId($receipt),
        ];
    }

    private function commandId(?string $commandId): string
    {
        $commandId = trim((string) $commandId);

        return $commandId === '' ? (string) Str::uuid() : $commandId;
    }
}
