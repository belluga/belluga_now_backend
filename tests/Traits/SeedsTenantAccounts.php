<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Application\Accounts\AccountPublicationStateService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use Illuminate\Support\Str;

trait SeedsTenantAccounts
{
    protected function seedAccountWithRole(array $permissions = ['account-users:*']): array
    {
        $tenant = $this->resolveTenantForAccountSeed();
        $tenant->makeCurrent();

        $account = Account::create([
            'name' => 'Account '.Str::uuid()->toString(),
            'document' => strtoupper(Str::random(14)),
            'publication' => [
                'status' => AccountPublicationStateService::PUBLISHED,
                'publish_at' => null,
            ],
        ]);

        $role = $account->roleTemplates()->create([
            'name' => 'Account Admin',
            'description' => 'Primary account administrator',
            'permissions' => $permissions,
        ]);

        return [$account, $role];
    }

    protected function resolveTenantForAccountSeed(): Tenant
    {
        return $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
    }
}
