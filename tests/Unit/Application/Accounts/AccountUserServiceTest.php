<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountUserServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $databaseBootstrapped = false;

    private static bool $systemInitialized = false;

    private AccountUserService $service;

    private Account $account;

    private AccountRoleTemplate $roleTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$databaseBootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            self::$databaseBootstrapped = true;
        }

        if (! self::$systemInitialized) {
            $this->initializeSystem();
            self::$systemInitialized = true;
        }

        [$this->account, $this->roleTemplate] = $this->seedAccountWithRole(['account-users:*']);
        $this->account->makeCurrent();

        $this->service = $this->app->make(AccountUserService::class);
    }

    public function testCreateRegistersNewAccountUser(): void
    {
        $user = $this->service->create(
            $this->account,
            [
                'name' => 'Tenant Operator',
                'email' => 'operator@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->roleTemplate->_id
        );

        $this->assertSame('Tenant Operator', $user->name);
        $this->assertSame('operator@example.org', $user->emails[0]);
        $this->assertTrue($user->haveAccessTo($this->account));
        $this->assertTrue(
            collect($user->account_roles)->contains(function (array $role): bool {
                return ($role['account_id'] ?? null) === $this->account->id;
            })
        );
    }

    public function testCreateRestoresSoftDeletedUserAndReusesIdentity(): void
    {
        $existing = $this->service->create(
            $this->account,
            [
                'name' => 'Archived User',
                'email' => 'archived@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->roleTemplate->_id
        );

        $existing->delete();

        $user = $this->service->create(
            $this->account,
            [
                'name' => 'Archived User',
                'email' => 'archived@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->roleTemplate->_id
        );

        $this->assertNull($user->deleted_at);
        $this->assertTrue($user->haveAccessTo($this->account));
        $this->assertSame((string) $existing->_id, (string) $user->_id);
    }

    public function testRemoveSoftDeletesUserWhenNoOtherAccess(): void
    {
        $user = $this->service->create(
            $this->account,
            [
                'name' => 'Disposable User',
                'email' => 'disposable@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->roleTemplate->_id
        );

        $this->service->remove($this->account, $user);

        $this->assertSoftDeleted($user->getTable(), ['_id' => $user->_id]);
    }

    public function testRemoveKeepsUserWhenOtherAccountAccessExists(): void
    {
        [$secondAccount, $secondRole] = $this->seedAccountWithRole(['account-users:view']);

        $user = $this->service->create(
            $this->account,
            [
                'name' => 'Shared User',
                'email' => 'shared@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->roleTemplate->_id
        );

        $user = $this->service->create(
            $secondAccount,
            [
                'name' => 'Shared User',
                'email' => 'shared@example.org',
                'password' => 'Secret!234',
            ],
            (string) $secondRole->_id
        );

        $this->service->remove($this->account, $user);

        $this->assertFalse($user->fresh()->trashed());
        $this->assertTrue($user->haveAccessTo($secondAccount));
    }

    public function testUpdatePersistsAttributesAndSyncsPassword(): void
    {
        $user = $this->service->create(
            $this->account,
            [
                'name' => 'Profile User',
                'email' => 'profile@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->roleTemplate->_id
        );

        $updated = $this->service->update($user, [
            'name' => 'Profile User Updated',
            'password' => 'NewSecret!234',
        ]);

        $this->assertSame('Profile User Updated', $updated->name);
        $this->assertNotNull($updated->password);
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
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-alpha.test']
        );

        $service->initialize($payload);
    }
}
