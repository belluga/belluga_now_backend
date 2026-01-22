<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;

class AccountProfileOwnershipService
{
    public function deriveOwnershipState(Account $account): string
    {
        if ($this->isTenantOwned($account)) {
            return 'tenant_owned';
        }

        if ($this->hasUserOperator($account)) {
            return 'user_owned';
        }

        return 'unmanaged';
    }

    public function isTenantOwned(Account $account): bool
    {
        $tenant = Tenant::current();

        if ($tenant === null || empty($tenant->organization_id)) {
            return false;
        }

        return (string) $account->organization_id === (string) $tenant->organization_id;
    }

    public function hasUserOperator(Account $account): bool
    {
        return AccountUser::query()
            ->where('account_roles.account_id', (string) $account->_id)
            ->exists();
    }
}
