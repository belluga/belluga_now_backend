<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;

class AccountProfileRepairService
{
    /**
     * @return array{total_accounts:int, missing_count:int, missing_account_ids:array<int, string>}
     */
    public function auditMissingProfiles(): array
    {
        $accounts = Account::query()->get();
        $totalAccounts = $accounts->count();

        $profileAccountIds = AccountProfile::query()
            ->pluck('account_id')
            ->map(static fn ($value): string => (string) $value)
            ->filter(static fn (string $value): bool => trim($value) !== '')
            ->values()
            ->all();
        $profileLookup = array_fill_keys($profileAccountIds, true);

        $missingAccountIds = [];
        foreach ($accounts as $account) {
            $accountId = (string) $account->_id;
            if (! isset($profileLookup[$accountId])) {
                $missingAccountIds[] = $accountId;
            }
        }

        return [
            'total_accounts' => $totalAccounts,
            'missing_count' => count($missingAccountIds),
            'missing_account_ids' => $missingAccountIds,
        ];
    }

    /**
     * @return array{audited:array{total_accounts:int, missing_count:int, missing_account_ids:array<int, string>}, created_count:int}
     */
    public function repairMissingProfiles(string $profileType = 'personal'): array
    {
        $audit = $this->auditMissingProfiles();
        if ($audit['missing_count'] === 0) {
            return [
                'audited' => $audit,
                'created_count' => 0,
            ];
        }

        $createdCount = 0;
        foreach ($audit['missing_account_ids'] as $accountId) {
            $account = Account::query()->where('_id', $accountId)->first();
            if (! $account) {
                continue;
            }

            AccountProfile::query()->create([
                'account_id' => (string) $account->_id,
                'profile_type' => $profileType,
                'display_name' => (string) ($account->name ?? 'Account Profile'),
                'is_active' => true,
            ]);
            $createdCount++;
        }

        return [
            'audited' => $audit,
            'created_count' => $createdCount,
        ];
    }
}

