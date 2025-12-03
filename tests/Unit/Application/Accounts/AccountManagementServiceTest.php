<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\Accounts\AccountManagementService;
use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountManagementServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private AccountManagementService $service;

    private AccountUserService $userService;

    private Account $account;

    private AccountRoleTemplate $role;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account, $this->role] = $this->seedAccountWithRole(['account-users:*']);
        $this->account->makeCurrent();

        $this->service = $this->app->make(AccountManagementService::class);
        $this->userService = $this->app->make(AccountUserService::class);
    }

    public function testCreateAccountWithAdminRole(): void
    {
        $name = fake()->unique()->company();

        $result = $this->service->create([
            'name' => $name,
            'document' => [
                'type' => 'cpf',
                'number' => fake()->unique()->numerify('###################'),
            ],
        ]);

        $this->assertArrayHasKey('account', $result);
        $this->assertArrayHasKey('role', $result);
        $this->assertSame($name, $result['account']->name);
        $this->assertSame(['*'], $result['role']->permissions);

        $this->service->forceDelete($result['account']);
    }

    public function testDeleteAccountSoftDeletesRoleTemplates(): void
    {
        $account = $this->service->create([
            'name' => fake()->unique()->company(),
            'document' => ['type' => 'cpf', 'number' => fake()->unique()->numerify('###################')],
        ])['account'];

        $this->service->delete($account);

        $this->assertSoftDeleted('account_role_templates', ['account_id' => $account->id], 'tenant');

        $this->service->forceDelete($account);
    }

    public function testAttachAndDetachUser(): void
    {
        $user = $this->userService->create($this->account, [
            'name' => 'Member',
            'email' => 'member@example.org',
            'password' => 'Secret!234',
        ], (string) $this->role->_id);

        $role = $this->account->roleTemplates()->create([
            'name' => 'Viewer',
            'permissions' => ['account-users:view'],
        ]);

        $this->service->attachUser($this->account, $user, $role);

        $this->assertTrue(
            collect($user->fresh()->account_roles)->contains(static function (array $embedded) use ($role): bool {
                return ($embedded['slug'] ?? null) === $role->slug;
            })
        );

        $this->service->detachUser($this->account, $user, $role);

        $this->assertFalse(
            collect($user->fresh()->account_roles)->contains(static function (array $embedded) use ($role): bool {
                return ($embedded['slug'] ?? null) === $role->slug;
            })
        );
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Epsilon', 'subdomain' => 'tenant-epsilon'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-epsilon.test']
        );

        $service->initialize($payload);
    }
}
