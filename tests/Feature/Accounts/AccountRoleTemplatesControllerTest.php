<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\Accounts\AccountRoleTemplateService;
use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountRoleTemplatesControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplateService $roleService;

    private AccountUserService $userService;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account] = $this->seedAccountWithRole(['account-roles:*']);
        $this->account->makeCurrent();

        $this->roleService = $this->app->make(AccountRoleTemplateService::class);
        $this->userService = $this->app->make(AccountUserService::class);

        $operatorRole = $this->roleService->create($this->account, [
            'name' => 'Operator',
            'description' => 'Account operator',
            'permissions' => [
                'account-roles:view',
                'account-roles:create',
                'account-roles:update',
                'account-roles:delete',
            ],
        ]);

        $operator = $this->userService->create($this->account, [
            'name' => 'Operator User',
            'email' => 'operator+' . uniqid('', true) . '@example.org',
            'password' => 'Secret!234',
        ], (string) $operatorRole->_id);

        Sanctum::actingAs($operator, $operatorRole->permissions);

        $this->baseUrl = sprintf('api/v1/accounts/%s/roles', $this->account->slug);
    }

    public function testStoreCreatesRole(): void
    {
        $response = $this->postJson($this->baseUrl, [
            'name' => 'Support',
            'description' => 'Handles support',
            'permissions' => ['account-users:view'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Support');

        $this->assertDatabaseHas('account_role_templates', [
            'name' => 'Support',
        ], 'tenant');
    }

    public function testUpdateAdjustsPermissions(): void
    {
        $role = $this->roleService->create($this->account, [
            'name' => 'Editors',
            'description' => 'Content editors',
            'permissions' => ['account-users:view'],
        ]);

        $response = $this->patchJson($this->baseUrl . '/' . $role->_id, [
            'permissions' => [
                'add' => ['account-users:create'],
                'remove' => ['account-users:view'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.permissions', ['account-users:create']);
    }

    public function testDestroyReassignsToFallback(): void
    {
        $fallback = $this->roleService->create($this->account, [
            'name' => 'Fallback',
            'description' => 'Fallback role',
            'permissions' => ['account-users:view'],
        ]);

        $roleToDelete = $this->roleService->create($this->account, [
            'name' => 'Disposable',
            'description' => 'Disposable',
            'permissions' => ['account-users:create'],
        ]);

        $user = $this->createAccountUserWithRole($roleToDelete);

        $response = $this->deleteJson($this->baseUrl . '/' . $roleToDelete->_id, [
            'background_role_id' => (string) $fallback->_id,
        ]);

        $response->assertOk();

        $this->assertSoftDeleted('account_role_templates', ['_id' => $roleToDelete->_id], 'tenant');
        $this->assertEquals(
            $fallback->slug,
            $user->fresh()?->account_roles[0]['slug']
        );
    }

    private function createAccountUserWithRole(AccountRoleTemplate $role): AccountUser
    {
        return $this->userService->create($this->account, [
            'name' => 'Fixture User',
            'email' => 'fixture+' . uniqid('', true) . '@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Beta', 'subdomain' => 'tenant-beta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-beta.test']
        );

        $service->initialize($payload);
    }
}
