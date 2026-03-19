<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\StaticProfileType;
use App\Models\Tenants\TenantSettings;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class MapPoiRebuildCommandTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();

        MapPoi::query()->delete();
        StaticAsset::query()->delete();
        StaticProfileType::query()->delete();
        TenantSettings::query()->delete();
    }

    public function test_rebuild_command_respects_map_ingest_enable_toggle(): void
    {
        TenantSettings::create([
            'map_ingest' => [
                'rebuild' => [
                    'enabled' => false,
                    'batch_size' => 200,
                ],
            ],
        ]);

        Tenant::query()->firstOrFail()->makeCurrent();

        $this->artisan('map-pois:rebuild static_assets')
            ->expectsOutputToContain('Map rebuild is disabled by tenant settings')
            ->assertExitCode(1);
    }

    public function test_rebuild_command_rebuilds_static_asset_projections(): void
    {
        TenantSettings::create([
            'map_ingest' => [
                'rebuild' => [
                    'enabled' => true,
                    'batch_size' => 50,
                ],
            ],
        ]);

        StaticProfileType::create([
            'type' => 'poi',
            'label' => 'POI',
            'map_category' => 'beach',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
                'has_taxonomies' => false,
            ],
        ]);

        $asset = StaticAsset::query()->create([
            'profile_type' => 'poi',
            'display_name' => 'Rebuild Static Asset',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.00001, -20.00001],
            ],
            'is_active' => true,
        ]);

        $this->assertFalse(
            MapPoi::query()
                ->where('ref_type', 'static')
                ->where('ref_id', (string) $asset->_id)
                ->exists()
        );

        Tenant::query()->firstOrFail()->makeCurrent();

        $this->artisan('map-pois:rebuild static_assets --batch-size=25')
            ->expectsOutputToContain('Map rebuild completed')
            ->assertExitCode(0);

        $projection = MapPoi::query()
            ->where('ref_type', 'static')
            ->where('ref_id', (string) $asset->_id)
            ->first();

        $this->assertNotNull($projection);
        $this->assertSame('beach', $projection?->category);
        $this->assertTrue((bool) $projection?->is_active);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
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
            tenantDomains: ['tenant-zeta.test'],
        );

        $service->initialize($payload);
    }
}
