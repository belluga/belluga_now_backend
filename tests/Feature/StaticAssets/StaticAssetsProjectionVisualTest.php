<?php

declare(strict_types=1);

namespace Tests\Feature\StaticAssets;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\StaticProfileType;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class StaticAssetsProjectionVisualTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

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

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
    }

    public function test_static_asset_create_projects_map_poi_with_type_visual_snapshot(): void
    {
        StaticAsset::query()->delete();
        StaticProfileType::query()->delete();
        MapPoi::query()->delete();

        StaticProfileType::create([
            'type' => 'beach',
            'label' => 'Beach',
            'map_category' => 'beach',
            'allowed_taxonomies' => [],
            'poi_visual' => [
                'mode' => 'icon',
                'icon' => 'beach_access',
                'color' => '#1E88E5',
                'icon_color' => '#0B1F33',
            ],
            'capabilities' => [
                'is_poi_enabled' => true,
            ],
        ]);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}static_assets",
            [
                'profile_type' => 'beach',
                'display_name' => 'Praia da Areia Preta',
                'location' => ['lat' => -20.66500, 'lng' => -40.49300],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $assetId = (string) $response->json('data.id');
        $this->assertNotSame('', $assetId);

        $projection = MapPoi::query()
            ->where('ref_type', 'static')
            ->where('ref_id', $assetId)
            ->first();

        $this->assertNotNull($projection);
        $this->assertSame('beach', (string) ($projection->category ?? ''));
        $this->assertSame('icon', data_get($projection->visual, 'mode'));
        $this->assertSame('beach_access', data_get($projection->visual, 'icon'));
        $this->assertSame('#1E88E5', data_get($projection->visual, 'color'));
        $this->assertSame('#0B1F33', data_get($projection->visual, 'icon_color'));
        $this->assertSame('type_definition', data_get($projection->visual, 'source'));
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $initializer */
        $initializer = app(SystemInitializationService::class);

        $initializer->initialize(new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: [
                'name' => 'Root User',
                'email' => 'root@example.org',
                'password' => 'Secret!234',
            ],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        ));
    }
}
