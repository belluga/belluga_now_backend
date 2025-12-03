<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\Accounts\AccountUserAccessService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountUserAccessServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private AccountUserAccessService $service;

    private AccountUser $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->service = $this->app->make(AccountUserAccessService::class);

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $this->account = Account::create([
            'name' => 'Test Account ' . uniqid(),
            'document' => (string) random_int(100000000, 999999999),
        ]);

        $this->user = AccountUser::create([
            'name' => 'Account Admin',
            'emails' => ['admin+' . uniqid() . '@tenant.test'],
            'password' => Hash::make('Secret!234'),
            'identity_state' => 'registered',
            'registered_at' => Carbon::now(),
        ]);

        $this->service->ensureEmail($this->user, $this->user->emails[0]);
        $this->service->syncCredential($this->user, 'password', $this->user->emails[0], (string) $this->user->password);

        $this->user->accountRoles()->create([
            'name' => 'Operator',
            'permissions' => ['account-users:*'],
            'account_id' => (string) $this->account->_id,
            'slug' => 'operator',
        ]);
    }

    public function testAccountAccessIdsIncludesAssignedAccount(): void
    {
        $ids = $this->service->accountAccessIds($this->user);

        $this->assertNotEmpty($ids);
        $this->assertContains((string) $this->account->_id, $ids);
    }

    public function testSyncCredentialStoresPassword(): void
    {
        $credential = $this->service->syncCredential($this->user, 'password', 'user@example.org', 'secret-hash');

        $this->assertSame('user@example.org', $credential['subject']);
        $this->assertSame('password', $credential['provider']);
    }

    public function testEnsureEmailPersistsNewEntry(): void
    {
        $email = 'additional+' . uniqid('', true) . '@example.org';
        $this->service->ensureEmail($this->user, $email);

        $this->user->refresh();
        $this->assertContains($email, $this->user->emails ?? []);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Delta', 'subdomain' => 'tenant-delta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-delta.test']
        );

        $service->initialize($payload);
    }
}
