<?php

namespace Tests;

use App\Application\Accounts\AccountManagementService;
use App\Models\Tenants\Account as TenantAccount;
use Tests\Helpers\AccountLabels;

abstract class TestCaseAccount extends TestCaseTenant
{
    abstract protected AccountLabels $account {
        get;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = $this->ensureCanonicalTenantExists($this->tenant);
        $tenant->makeCurrent();

        $slug = $this->account->slug;
        if ($slug === '') {
            return;
        }

        $account = TenantAccount::query()->where('slug', $slug)->first();
        if (! $account instanceof TenantAccount) {
            /** @var AccountManagementService $accountManagementService */
            $accountManagementService = app(AccountManagementService::class);
            $created = $accountManagementService->create([
                'name' => $this->account->name,
                'ownership_state' => 'unmanaged',
            ]);
            $account = $created['account'];
        }

        $this->account->id = (string) $account->_id;
        $this->account->slug = $account->slug;
        $this->account->name = $account->name;
        $this->account->role_admin->id = (string) ($account->roleTemplates()->first()?->_id ?? '');
    }

    protected string $base_api_account {
        get {
            return "{$this->base_api_tenant}accounts/{$this->account->slug}/";
        }
    }
}
