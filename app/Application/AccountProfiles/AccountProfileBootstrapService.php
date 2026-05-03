<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountManagementService;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Facades\DB;

class AccountProfileBootstrapService
{
    private const string PERSONAL_PROFILE_TYPE = 'personal';

    public function __construct(
        private readonly AccountManagementService $accountService,
        private readonly AccountProfileManagementService $profileService,
        private readonly AccountProfileRegistrySeeder $registrySeeder,
    ) {}

    public function ensurePersonalAccount(AccountUser $user): void
    {
        $this->registrySeeder->ensureDefaults();

        if ($this->personalProfileExists($user)) {
            return;
        }

        $displayName = $user->name ?: 'Personal';
        $documentNumber = 'PERSONAL-'.(string) $user->_id;
        $insideTransaction = DB::connection('tenant')->transactionLevel() > 0;

        $payload = [
            'name' => $displayName,
            'ownership_state' => 'unmanaged',
            'document' => [
                'type' => 'cpf',
                'number' => $documentNumber,
            ],
            'created_by' => (string) $user->_id,
            'created_by_type' => 'tenant',
            'updated_by' => (string) $user->_id,
            'updated_by_type' => 'tenant',
        ];

        $result = $insideTransaction
            ? $this->accountService->createWithinCurrentTransaction($payload)
            : $this->accountService->create($payload);

        $account = $result['account'];
        $role = $result['role'];

        if ($insideTransaction) {
            $this->accountService->attachUserWithinCurrentTransaction($account, $user, $role);
        } else {
            $this->accountService->attachUser($account, $user, $role);
        }

        $profilePayload = [
            'account_id' => (string) $account->_id,
            'profile_type' => self::PERSONAL_PROFILE_TYPE,
            'display_name' => $displayName,
            'created_by' => (string) $user->_id,
            'created_by_type' => 'tenant',
            'updated_by' => (string) $user->_id,
            'updated_by_type' => 'tenant',
        ];

        if ($insideTransaction) {
            $this->profileService->createWithinCurrentTransaction($profilePayload);
        } else {
            $this->profileService->create($profilePayload);
        }
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
