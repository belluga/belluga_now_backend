<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\Accounts\AccountUserService;
use App\Application\Accounts\TenantUserManagementService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class TenantUserManagementServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private TenantUserManagementService $service;

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

        $this->service = $this->app->make(TenantUserManagementService::class);
        $this->userService = $this->app->make(AccountUserService::class);
    }

    public function test_find_returns_existing_user(): void
    {
        $user = $this->createUser('show@example.org');

        $found = $this->service->find((string) $user->_id);

        $this->assertTrue(Str::startsWith($found->emails[0], 'show+'));
        $this->assertTrue(Str::endsWith($found->emails[0], '@example.org'));
    }

    public function test_delete_soft_deletes_user(): void
    {
        $user = $this->createUser('delete@example.org');

        $this->service->delete((string) $user->_id);

        $this->assertSoftDeleted('account_users', ['_id' => $user->_id], 'tenant');
    }

    public function test_restore_brings_back_soft_deleted_user(): void
    {
        $user = $this->createUser('restore@example.org');
        $user->delete();

        $restored = $this->service->restore((string) $user->_id);

        $this->assertFalse($restored->trashed());
    }

    public function test_force_delete_removes_user(): void
    {
        $user = $this->createUser('force@example.org');
        $user->delete();

        $this->service->forceDelete((string) $user->_id);

        $this->assertDatabaseMissing('account_users', ['_id' => $user->_id], 'tenant');
    }

    private function createUser(string $email): AccountUser
    {
        $emailParts = explode('@', $email, 2);
        $uniqueEmail = isset($emailParts[1])
            ? sprintf('%s+%s@%s', $emailParts[0], Str::uuid()->toString(), $emailParts[1])
            : $email.'+'.Str::uuid()->toString();

        return $this->userService->create($this->account, [
            'name' => 'Tenant User',
            'email' => $uniqueEmail,
            'password' => 'Secret!234',
        ], (string) $this->role->_id);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Eta', 'subdomain' => 'tenant-eta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-eta.test']
        );

        $service->initialize($payload);
    }
}
