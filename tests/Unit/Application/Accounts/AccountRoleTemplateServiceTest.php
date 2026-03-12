<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\Accounts\AccountRoleTemplateService;
use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

#[Group('atlas-critical')]
class AccountRoleTemplateServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private AccountRoleTemplateService $service;

    private AccountUserService $accountUserService;

    private Account $account;

    private AccountRoleTemplate $primaryRole;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account, $this->primaryRole] = $this->seedAccountWithRole(['account-roles:*']);
        $this->account->makeCurrent();

        $this->service = $this->app->make(AccountRoleTemplateService::class);
        $this->accountUserService = $this->app->make(AccountUserService::class);
    }

    public function test_create_adds_role_template(): void
    {
        $role = $this->service->create($this->account, [
            'name' => 'Support Agent',
            'description' => 'Handles support tickets',
            'permissions' => ['account-users:view'],
        ]);

        $this->assertSame('Support Agent', $role->name);
        $this->assertSame(['account-users:view'], $role->permissions);
    }

    public function test_create_rejects_duplicate(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Role already exists for this account.');

        $this->service->create($this->account, [
            'name' => $this->primaryRole->name,
            'description' => 'Duplicate name',
            'permissions' => ['account-users:view'],
        ]);
    }

    public function test_update_mutates_permissions(): void
    {
        $role = $this->service->create($this->account, [
            'name' => 'Editors',
            'description' => 'Content editors',
            'permissions' => ['account-users:view'],
        ]);

        $updated = $this->service->update($role, [
            'permissions' => [
                'add' => ['account-users:create', 'account-users:update'],
                'remove' => ['account-users:view'],
            ],
            'description' => 'Updated description',
        ]);

        $this->assertSame(
            ['account-users:create', 'account-users:update'],
            $updated->permissions
        );
        $this->assertSame('Updated description', $updated->description);
    }

    public function test_delete_reassigns_users_to_fallback_role(): void
    {
        $fallback = $this->service->create($this->account, [
            'name' => 'Fallback',
            'description' => 'Fallback permissions',
            'permissions' => ['account-users:view'],
        ]);

        $roleToDelete = $this->service->create($this->account, [
            'name' => 'Temporary',
            'description' => 'Temporary role',
            'permissions' => ['account-users:create'],
        ]);

        $user = $this->accountUserService->create($this->account, [
            'name' => 'Account Operator',
            'email' => 'operator+'.uniqid('', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $roleToDelete->_id);

        $this->service->delete($this->account, $roleToDelete, $fallback);

        $user = $user->fresh();
        $this->assertNotNull($user);
        $this->assertTrue(
            collect($user->account_roles)->pluck('slug')->contains($fallback->slug)
        );
        $this->assertSoftDeleted('account_role_templates', ['_id' => $roleToDelete->_id], 'tenant');
    }

    public function test_restore_brings_role_back(): void
    {
        $role = $this->service->create($this->account, [
            'name' => 'Archived',
            'description' => 'Archived role',
            'permissions' => ['account-users:view'],
        ]);

        $this->service->delete($this->account, $role, $this->primaryRole);

        $restored = $this->service->restore($this->account, (string) $role->_id);

        $this->assertNull($restored->deleted_at);
        $this->assertSame('Archived', $restored->name);
    }

    public function test_force_delete_removes_role(): void
    {
        $role = $this->service->create($this->account, [
            'name' => 'Removable',
            'description' => 'Role to be force deleted',
            'permissions' => ['account-users:view'],
        ]);

        $this->service->delete($this->account, $role, $this->primaryRole);
        $this->service->forceDelete($this->account, (string) $role->_id);

        $this->assertDatabaseMissing('account_role_templates', ['_id' => $role->_id], 'tenant');
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Alpha', 'subdomain' => 'tenant-alpha'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-alpha.test']
        );

        $service->initialize($payload);
    }
}
