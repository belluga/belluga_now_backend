<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Accounts;

use App\Application\Accounts\TenantUserQueryService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantUserQueryServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private TenantUserQueryService $service;

    private string $filterName;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->service = $this->app->make(TenantUserQueryService::class);
        $this->filterName = 'Tenant Filter '.Str::uuid()->toString();

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $this->seedUsers();
    }

    public function test_filters_by_name(): void
    {
        $paginator = $this->service->paginate(
            ['filter' => ['name' => $this->filterName]],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(1, $paginator->total());
        $this->assertSame($this->filterName, $paginator->items()[0]['name']);
    }

    public function test_filters_by_email(): void
    {
        $email = 'tenant.filter+'.uniqid('', true).'@example.org';
        $this->createUser([
            'name' => 'Email Match',
            'emails' => [$email],
        ]);

        $paginator = $this->service->paginate(
            ['filter' => ['emails' => $email]],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(1, $paginator->total());
        $this->assertSame('Email Match', $paginator->items()[0]['name']);
    }

    public function test_filters_by_phone(): void
    {
        $phone = '+55119'.random_int(1000000, 9999999);
        $target = $this->createUser([
            'name' => 'Phone Match',
            'phones' => [$phone],
        ]);

        $paginator = $this->service->paginate(
            ['filter' => ['phones' => $phone]],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(1, $paginator->total());
        $this->assertSame((string) $target->_id, $paginator->items()[0]['_id']);
    }

    public function test_filters_by_registration_range(): void
    {
        $from = Carbon::now()->subHour()->toDateString();
        $to = Carbon::now()->addHour()->toDateString();

        $paginator = $this->service->paginate(
            ['filter' => ['registered_at' => ['from' => $from, 'to' => $to]]],
            includeArchived: false,
            perPage: 15
        );

        $this->assertGreaterThanOrEqual(2, $paginator->total());
    }

    public function test_includes_archived_users_when_requested(): void
    {
        $email = 'archived.tenant+'.uniqid('', true).'@example.org';
        $user = $this->createUser([
            'name' => 'Archived Tenant User',
            'emails' => [$email],
        ]);
        $user->delete();

        $active = $this->service->paginate(['filter' => ['emails' => $email]], includeArchived: false, perPage: 15);
        $withArchived = $this->service->paginate(['filter' => ['emails' => $email]], includeArchived: true, perPage: 15);

        $this->assertSame(0, $active->total());
        $this->assertSame(1, $withArchived->total());
    }

    public function test_unsupported_sort_falls_back_to_default_order(): void
    {
        $baseline = $this->service->paginate([], includeArchived: false, perPage: 15);
        $fallback = $this->service->paginate(['sort' => 'not-valid'], includeArchived: false, perPage: 15);

        $this->assertGreaterThan(0, $baseline->total());
        $this->assertSame(
            $baseline->items()[0]['_id'],
            $fallback->items()[0]['_id']
        );
    }

    /**
     * @return array<int, AccountUser>
     */
    private function seedUsers(): array
    {
        return [
            $this->createUser([
                'name' => $this->filterName,
                'emails' => ['tenant.filter+'.uniqid('', true).'@example.org'],
                'registered_at' => Carbon::now()->subHours(2),
                'created_at' => Carbon::now()->subHours(2),
            ]),
            $this->createUser([
                'name' => 'Baseline Tenant',
                'emails' => ['baseline.tenant+'.uniqid('', true).'@example.org'],
                'registered_at' => Carbon::now()->subHour(),
                'created_at' => Carbon::now()->subHour(),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createUser(array $overrides): AccountUser
    {
        $payload = array_merge([
            'name' => 'Tenant Fixture',
            'emails' => ['tenant.fixture+'.uniqid('', true).'@example.org'],
            'phones' => [],
            'registered_at' => Carbon::now(),
            'password' => Hash::make('Secret!234'),
            'identity_state' => 'registered',
            'created_at' => Carbon::now(),
        ], $overrides);

        return AccountUser::create($payload);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord Beta'],
            tenant: ['name' => 'Tenant Query', 'subdomain' => 'tenant-query'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root.tenant@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-query.test']
        );

        $service->initialize($payload);
    }
}
