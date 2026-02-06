<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\MapPoi;
use App\Models\Tenants\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;
use App\Application\Accounts\AccountUserService;

class MapPoiControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;
    private AccountUserService $userService;
    private AccountUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        MapPoi::query()->delete();

        [$this->account] = $this->seedAccountWithRole(['*']);
        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser(['*']);

        Sanctum::actingAs($this->user, ['*']);

        TenantSettings::query()->delete();
        TenantSettings::create([
            'map_ui' => [
                'radius' => [
                    'min_km' => 1,
                    'default_km' => 5,
                    'max_km' => 50,
                ],
                'poi_time_window_hours' => [
                    'past' => 6,
                    'future' => 720,
                ],
            ],
        ]);
    }

    public function testMapPoisRequiresAuth(): void
    {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();

        $response = $this->getJson("{$this->base_api_tenant}map/v2/pois");
        $response->assertStatus(401);
    }

    public function testMapPoisStacksSameExactKey(): void
    {
        $this->createPoi(['ref_id' => 'ref-1', 'priority' => 40]);
        $this->createPoi(['ref_id' => 'ref-2', 'priority' => 60]);

        $response = $this->getJson("{$this->base_api_tenant}map/v2/pois");
        $response->assertStatus(200);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]['stack_count']);
        $this->assertSame('ref-2', $items[0]['top_poi']['ref_id']);
    }

    public function testMapPoisFiltersByTimeWindow(): void
    {
        $now = Carbon::now();

        $this->createPoi([
            'ref_id' => 'recent',
            'time_anchor_at' => $now->copy()->subHours(2),
        ]);

        $this->createPoi([
            'ref_id' => 'old',
            'time_anchor_at' => $now->copy()->subHours(12),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}map/v2/pois");
        $response->assertStatus(200);

        $items = $response->json('items');
        $flat = collect($items)->flatMap(fn ($stack) => $stack['items'])->pluck('ref_id')->all();

        $this->assertContains('recent', $flat);
        $this->assertNotContains('old', $flat);
    }

    public function testMapPoisIncludesDistanceWhenOriginProvided(): void
    {
        $this->createPoi(['ref_id' => 'near', 'location' => ['type' => 'Point', 'coordinates' => [-40.01, -20.01]]]);

        $response = $this->getJson("{$this->base_api_tenant}map/v2/pois?origin_lat=-20&origin_lng=-40&max_distance_meters=10000");
        $response->assertStatus(200);

        $items = $response->json('items');
        $this->assertNotEmpty($items[0]['items'][0]['distance_meters']);
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Test Role',
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Test User',
            'email' => uniqid('map-user', true) . '@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createPoi(array $overrides = []): MapPoi
    {
        $lat = -20.0;
        $lng = -40.0;
        $exactKey = number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '');

        $payload = array_merge([
            'tenant_id' => (string) Tenant::resolve()->_id,
            'ref_type' => 'static',
            'ref_id' => Str::uuid()->toString(),
            'name' => 'POI',
            'category' => 'beach',
            'tags' => [],
            'taxonomy_terms' => [],
            'priority' => 40,
            'location' => [
                'type' => 'Point',
                'coordinates' => [$lng, $lat],
            ],
            'exact_key' => $exactKey,
            'is_active' => true,
        ], $overrides);

        return MapPoi::create($payload);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);

        $tenant = Tenant::query()->first();
        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? '');
        }
    }
}
