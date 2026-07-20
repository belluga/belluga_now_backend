<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountManagementService;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountProfileBootstrapService
{
    private const string PERSONAL_PROFILE_TYPE = 'personal';

    public function __construct(
        private readonly AccountManagementService $accountService,
        private readonly AccountProfileManagementService $profileService,
        private readonly AccountProfileRegistrySeeder $registrySeeder,
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileOutboxPublisher $outboxPublisher,
        private readonly AccountProfileOutboxDispatcher $outboxDispatcher,
    ) {}

    public function ensurePersonalAccount(
        AccountUser $user,
        ?AccountProfileTransactionContext $context = null,
    ): ?string {
        $this->registrySeeder->ensureDefaults();

        $userId = $this->userId($user);
        $commandId = "account-profile-bootstrap:{$userId}:personal";
        $fingerprint = $this->bootstrapFingerprint($user, $userId);

        if ($context !== null) {
            return $this->ensurePersonalAccountWithinTransaction(
                $user,
                $context,
                $commandId,
                $fingerprint,
            );
        }

        if (DB::connection('tenant')->transactionLevel() > 0) {
            throw new RuntimeException(
                'Account Profile bootstrap inside an active tenant transaction requires an explicit transaction context.',
            );
        }

        $eventId = $this->transactionRunner->run(
            fn (AccountProfileTransactionContext $transactionContext): ?string => $this->ensurePersonalAccountWithinTransaction(
                $user,
                $transactionContext,
                $commandId,
                $fingerprint,
            ),
            fn (): ?string => $this->reconcileCommittedBootstrap($commandId, $fingerprint),
        );

        if ($eventId !== null) {
            $this->outboxDispatcher->dispatchEvent($eventId);
        }

        return $eventId;
    }

    private function ensurePersonalAccountWithinTransaction(
        AccountUser $user,
        AccountProfileTransactionContext $context,
        string $commandId,
        string $fingerprint,
    ): ?string {
        if ($this->personalProfileExists($user)) {
            return null;
        }

        $userId = $this->userId($user);
        $displayName = $user->name ?: 'Personal';
        $documentNumber = 'PERSONAL-'.$userId;
        $accountPayload = [
            'name' => $displayName,
            'ownership_state' => 'unmanaged',
            'document' => [
                'type' => 'cpf',
                'number' => $documentNumber,
            ],
            'created_by' => $userId,
            'created_by_type' => 'tenant',
            'updated_by' => $userId,
            'updated_by_type' => 'tenant',
        ];

        $accountResult = $this->accountService->createWithinCurrentTransaction($accountPayload);
        $account = $accountResult['account'];
        $this->accountService->attachUserWithinCurrentTransaction($account, $user, $accountResult['role']);

        $profilePayload = [
            'account_id' => (string) $account->_id,
            'profile_type' => self::PERSONAL_PROFILE_TYPE,
            'display_name' => $displayName,
            'created_by' => $userId,
            'created_by_type' => 'tenant',
            'updated_by' => $userId,
            'updated_by_type' => 'tenant',
        ];

        $result = $this->profileService->createWithinTransactionContext(
            $profilePayload,
            $context,
            $commandId,
            $fingerprint,
        );

        return $result['outbox_event_id'];
    }

    private function reconcileCommittedBootstrap(string $commandId, string $fingerprint): ?string
    {
        $receipt = $this->outboxPublisher->committedReceipt($commandId);
        if ($receipt === null) {
            return null;
        }

        $this->outboxPublisher->assertReceiptMatches($receipt, $fingerprint);
        $profileId = trim((string) ($receipt['profile_id'] ?? ''));
        $eventId = trim((string) ($receipt['outbox_event_id'] ?? ''));
        if ($profileId === '' || $eventId === '') {
            return null;
        }

        return AccountProfile::withTrashed()->whereKey($profileId)->exists()
            ? $eventId
            : null;
    }

    private function bootstrapFingerprint(AccountUser $user, string $userId): string
    {
        return $this->outboxPublisher->fingerprintForCreate(
            [
                'profile_type' => self::PERSONAL_PROFILE_TYPE,
                'display_name' => $user->name ?: 'Personal',
                'created_by' => $userId,
                'created_by_type' => 'tenant',
                'updated_by' => $userId,
                'updated_by_type' => 'tenant',
            ],
            ['bootstrap_account_user_id' => $userId],
        );
    }

    private function userId(AccountUser $user): string
    {
        $userId = trim((string) ($user->_id ?? $user->getKey()));
        if ($userId === '') {
            throw new RuntimeException('Account Profile bootstrap requires a persisted Account User.');
        }

        return $userId;
    }

    private function personalProfileExists(AccountUser $user): bool
    {
        return AccountProfile::query()
            ->where('created_by', (string) $user->_id)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', self::PERSONAL_PROFILE_TYPE)
            ->where('deleted_at', null)
            ->exists();
    }
}
