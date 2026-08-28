<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONDocument;

/**
 * Shares the deletion fence checks between lifecycle writes and reference
 * cleanup without making the cleanup service depend on the lifecycle writer.
 */
final class AccountProfileMutationGate
{
    public const DELETION_ATTEMPTS_COLLECTION = 'account_profile_deletion_attempts';

    public const DELETION_GATE_ERROR = 'Account Profile mutation is temporarily blocked by account deletion.';

    public function __construct(
        private readonly AccountProfileDeletionBarrier $deletionBarrier,
    ) {}

    public function assertAccountMutationAllowed(string $accountId, ?AccountProfileTransactionContext $context = null): void
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            return;
        }

        if ($context !== null) {
            try {
                $rawId = new ObjectId($accountId);
            } catch (\Throwable) {
                $rawId = $accountId;
            }
            $account = $context->collection('accounts')->findOne(['_id' => $rawId], $context->rawOptions());
            $raw = $account instanceof BSONDocument ? $account->getArrayCopy() : (is_array($account) ? $account : []);
            $gate = $raw['account_profile_deletion_gate'] ?? null;
            if ($gate instanceof BSONDocument) {
                $gate = $gate->getArrayCopy();
            }
            if (! is_array($gate)) {
                return;
            }

            throw new ConcurrencyConflictException(self::DELETION_GATE_ERROR);
        }

        $account = Account::withTrashed()->find($accountId);
        if (! $account instanceof Account || $this->accountDeletionGate($account) === null) {
            return;
        }

        throw new ConcurrencyConflictException(self::DELETION_GATE_ERROR);
    }

    public function assertProfileMutationAllowed(
        AccountProfile $profile,
        ?AccountProfileTransactionContext $context = null,
    ): void {
        if (trim((string) $profile->getAttribute('account_profile_deletion_attempt_id')) !== '') {
            throw new ConcurrencyConflictException(self::DELETION_GATE_ERROR);
        }

        $this->assertNoActiveDeletionAttempt($profile, $context);
        $this->assertAccountMutationAllowed((string) $profile->account_id, $context);
    }

    /** @param array<string, mixed> $payload */
    public function assertProfileCreationAllowed(
        array $payload,
        AccountProfileTransactionContext $context,
    ): void {
        $this->assertAccountMutationAllowed((string) ($payload['account_id'] ?? ''), $context);
        if ((string) ($payload['profile_type'] ?? '') !== 'personal') {
            return;
        }

        $this->assertNoActiveDeletionAttemptForTenantUser(
            (string) ($payload['created_by_type'] ?? ''),
            (string) ($payload['created_by'] ?? ''),
            $context,
        );
        if ((string) ($payload['created_by_type'] ?? '') === 'tenant') {
            $this->deletionBarrier->touch($context, (string) ($payload['created_by'] ?? ''));
        }
    }

    /** @return array<string, mixed>|null */
    public function accountDeletionGate(Account $account): ?array
    {
        $gate = $account->getAttribute('account_profile_deletion_gate');
        if ($gate instanceof BSONDocument) {
            $gate = $gate->getArrayCopy();
        }

        return is_array($gate) ? $gate : null;
    }

    private function assertNoActiveDeletionAttempt(
        AccountProfile $profile,
        ?AccountProfileTransactionContext $context,
    ): void {
        if ((string) $profile->getAttribute('profile_type') !== 'personal') {
            return;
        }

        $this->assertNoActiveDeletionAttemptForTenantUser(
            (string) $profile->getAttribute('created_by_type'),
            (string) $profile->getAttribute('created_by'),
            $context,
        );
    }

    private function assertNoActiveDeletionAttemptForTenantUser(
        string $createdByType,
        string $userId,
        ?AccountProfileTransactionContext $context,
    ): void {
        if ($context === null || $createdByType !== 'tenant') {
            return;
        }

        $userId = trim($userId);
        if ($userId === '') {
            return;
        }

        $claim = $context->collection(self::DELETION_ATTEMPTS_COLLECTION)->findOne(
            [
                '_id' => $userId,
                'phase' => ['$ne' => 'completed'],
            ],
            $context->rawOptions(),
        );
        if ($claim !== null) {
            throw new ConcurrencyConflictException(self::DELETION_GATE_ERROR);
        }
    }
}
