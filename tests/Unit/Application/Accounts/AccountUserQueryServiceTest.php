<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\Accounts\AccountUserQueryService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountUserQueryServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private AccountUserQueryService $service;

    private Account $account;

    private string $filterEmail;

    private AccountUser $phoneFixture;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->service = $this->app->make(AccountUserQueryService::class);

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $this->account = Account::create([
            'name' => 'Query Account',
            'document' => (string) random_int(100000000, 999999999),
        ]);

        $role = $this->account->roleTemplates()->create([
            'name' => 'Operator',
            'permissions' => ['account-users:*'],
        ]);

        $this->seedUsers($role);
    }

    public function test_search_by_name(): void
    {
        $paginator = $this->service->paginate(
            $this->account,
            ['filter' => ['name' => 'Searchable']],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(1, $paginator->total());
        $this->assertSame('Searchable User', $paginator->items()[0]['name']);
    }

    public function test_search_by_email(): void
    {
        $paginator = $this->service->paginate(
            $this->account,
            ['filter' => ['emails' => $this->filterEmail]],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(1, $paginator->total());
        $this->assertSame('Email Filter', $paginator->items()[0]['name']);
    }

    public function test_search_by_phone_number(): void
    {
        $phone = '+551199999'.random_int(1000, 9999);
        $this->attachPhoneToFixture($this->phoneFixture, $phone);

        $paginator = $this->service->paginate(
            $this->account,
            ['filter' => ['phones' => $phone]],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(1, $paginator->total());
        $this->assertSame('Phone Filter', $paginator->items()[0]['name']);
    }

    public function test_search_by_registered_at_range(): void
    {
        $from = Carbon::now()->subDay()->toDateString();
        $to = Carbon::now()->addDay()->toDateString();

        $paginator = $this->service->paginate(
            $this->account,
            ['filter' => ['registered_at' => ['from' => $from, 'to' => $to]]],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(4, $paginator->total());
    }

    public function test_search_by_identity_state(): void
    {
        $paginator = $this->service->paginate(
            $this->account,
            ['filter' => ['identity_state' => 'validated']],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(1, $paginator->total());
        $this->assertSame('Validated User', $paginator->items()[0]['name']);
    }

    private function seedUsers(AccountRoleTemplate $role): void
    {
        $this->createUserWithRole($role, [
            'name' => 'Searchable User',
            'emails' => ['searchable+'.uniqid('', true).'@example.org'],
            'registered_at' => Carbon::now()->subHours(4),
        ]);

        $this->filterEmail = 'filter+'.uniqid('', true).'@example.org';
        $this->createUserWithRole($role, [
            'name' => 'Email Filter',
            'emails' => [$this->filterEmail],
            'registered_at' => Carbon::now()->subHours(2),
        ]);

        $this->phoneFixture = $this->createUserWithRole($role, [
            'name' => 'Phone Filter',
            'emails' => ['phone+'.uniqid('', true).'@example.org'],
            'registered_at' => Carbon::now()->subHour(),
        ]);

        $this->attachPhoneToFixture($this->phoneFixture, '+55119123'.random_int(1000, 9999));

        $this->createUserWithRole($role, [
            'name' => 'Validated User',
            'emails' => ['validated+'.uniqid('', true).'@example.org'],
            'identity_state' => 'validated',
            'registered_at' => Carbon::now()->subMinutes(5),
        ]);
    }

    private function attachPhoneToFixture(AccountUser $user, string $phone): void
    {
        $user->phones = [$phone];
        $user->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createUserWithRole(AccountRoleTemplate $role, array $overrides): AccountUser
    {
        $payload = array_merge([
            'emails' => ['user+'.uniqid('', true).'@example.org'],
            'password' => Hash::make('Secret!234'),
            'identity_state' => 'registered',
            'registered_at' => Carbon::now(),
        ], $overrides);

        $user = AccountUser::create($payload);

        $user->accountRoles()->create([
            'account_id' => (string) $this->account->_id,
            'permissions' => ['account-users:*'],
            'slug' => $role->slug,
            'name' => $role->name,
        ]);

        return $user;
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Omega', 'subdomain' => 'tenant-omega'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-omega.test']
        );

        $service->initialize($payload);
    }
}
