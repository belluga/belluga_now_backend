<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Branding;

use App\Application\Branding\BrandingManifestService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class BrandingManifestServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private BrandingManifestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->service = $this->app->make(BrandingManifestService::class);
    }

    public function testBuildManifestUsesTenantDataWhenAvailable(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $manifest = $this->service->buildManifest('tenant.example.test');

        $this->assertSame('Tenant Alpha', $manifest['name']);
        $this->assertCount(3, $manifest['icons']);
    }

    public function testResolveLogoSettingFallsBackToLandlord(): void
    {
        Tenant::forgetCurrent();

        $value = $this->service->resolveLogoSetting('light_logo_uri');

        $this->assertNotNull($value);
    }

    public function testAssetResponseReturnsNotFoundWhenMissing(): void
    {
        Storage::fake('public');

        $response = $this->service->assetResponse(null);

        $this->assertSame(404, $response->getStatusCode());
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
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
