<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LandlordUsers;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\LandlordUsers\LandlordUserQueryService;
use App\Models\Landlord\LandlordUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordUserQueryServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private LandlordUserQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->service = $this->app->make(LandlordUserQueryService::class);

        $this->seedUsers();
    }

    public function test_filters_by_name(): void
    {
        $paginator = $this->service->paginate(
            ['filter' => ['name' => 'Support Filter']],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(1, $paginator->total());
        $this->assertSame('Support Filter', $paginator->items()[0]['name']);
    }

    public function test_filters_by_email_address(): void
    {
        $targetEmail = 'filter.landlord+'.uniqid('', true).'@example.org';
        $this->createUser([
            'name' => 'Email Filter',
            'emails' => [$targetEmail],
        ]);

        $paginator = $this->service->paginate(
            ['filter' => ['emails' => $targetEmail]],
            includeArchived: false,
            perPage: 15
        );

        $this->assertSame(1, $paginator->total());
        $this->assertSame($targetEmail, $paginator->items()[0]['emails'][0]);
    }

    public function test_includes_archived_records_when_requested(): void
    {
        $email = 'archived.landlord+'.uniqid('', true).'@example.org';
        $archived = $this->createUser([
            'name' => 'Archived Landlord',
            'emails' => [$email],
        ]);
        $archived->delete();

        $withoutArchived = $this->service->paginate(
            ['filter' => ['emails' => $email]],
            includeArchived: false,
            perPage: 15
        );

        $withArchived = $this->service->paginate(
            ['filter' => ['emails' => $email]],
            includeArchived: true,
            perPage: 15
        );

        $this->assertSame(0, $withoutArchived->total());
        $this->assertSame(1, $withArchived->total());
    }

    public function test_unsupported_sort_falls_back_to_default_order(): void
    {
        $baseline = $this->service->paginate([], includeArchived: false, perPage: 15);
        $fallback = $this->service->paginate(['sort' => '-unsupported'], includeArchived: false, perPage: 15);

        $this->assertGreaterThan(0, $baseline->total());
        $this->assertSame(
            $baseline->items()[0]['_id'],
            $fallback->items()[0]['_id']
        );
    }

    public function test_sorts_by_name_descending(): void
    {
        $this->createUser([
            'name' => 'Alpha Landlord',
            'emails' => ['alpha.landlord+'.uniqid('', true).'@example.org'],
        ]);

        $this->createUser([
            'name' => 'Zulu Landlord',
            'emails' => ['zulu.landlord+'.uniqid('', true).'@example.org'],
        ]);

        $paginator = $this->service->paginate(
            ['sort' => '-name'],
            includeArchived: false,
            perPage: 15
        );

        $this->assertGreaterThanOrEqual(2, $paginator->total());
        $this->assertSame('Zulu Landlord', $paginator->items()[0]['name']);
    }

    private function seedUsers(): void
    {
        $this->createUser([
            'name' => 'Support Filter',
            'emails' => ['support.filter+'.uniqid('', true).'@example.org'],
            'created_at' => Carbon::now()->subHours(2),
        ]);

        $this->createUser([
            'name' => 'Baseline Staff',
            'emails' => ['baseline.staff+'.uniqid('', true).'@example.org'],
            'created_at' => Carbon::now()->subHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createUser(array $overrides): LandlordUser
    {
        $payload = array_merge([
            'name' => 'Landlord Fixture',
            'emails' => ['landlord.fixture+'.uniqid('', true).'@example.org'],
            'phones' => [],
            'password' => Hash::make('Secret!234'),
            'identity_state' => 'registered',
            'created_at' => Carbon::now(),
        ], $overrides);

        return LandlordUser::create($payload);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord Alpha'],
            tenant: ['name' => 'Tenant Sigma', 'subdomain' => 'tenant-sigma'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root.landlord@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-sigma.test']
        );

        $service->initialize($payload);
    }
}
