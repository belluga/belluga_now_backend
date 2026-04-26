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

    public function test_build_manifest_uses_tenant_data_when_available(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $manifest = $this->service->buildManifest('tenant.example.test');

        $this->assertSame('Tenant Alpha', $manifest['name']);
        $this->assertCount(3, $manifest['icons']);
    }

    public function test_resolve_logo_setting_falls_back_to_landlord(): void
    {
        Tenant::forgetCurrent();

        $value = $this->service->resolveLogoSetting('light_logo_uri');

        $this->assertNotNull($value);
    }

    public function test_asset_response_returns_not_found_when_missing(): void
    {
        Storage::fake('public');

        $response = $this->service->assetResponse(null);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_resolve_favicon_asset_falls_back_to_tenant_pwa_icon(): void
    {
        Storage::fake('public');

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $pwaIconPath = "tenants/{$tenant->slug}/pwa/icon-192x192.png";
        Storage::disk('public')->put($pwaIconPath, 'tenant-pwa-icon');

        $tenant->branding_data = [
            'logo_settings' => [
                'favicon_uri' => '',
            ],
            'pwa_icon' => [
                'icon192_uri' => 'https://tenant-alpha.test/icon/icon-192x192.png?v=tenant-pwa-icon',
                'icon512_uri' => '',
                'source_uri' => '',
                'icon_maskable512_uri' => '',
            ],
        ];
        $tenant->save();
        $tenant->fresh()?->makeCurrent();

        $resolvedAsset = $this->service->resolveFaviconAsset();
        $response = $this->service->assetResponse($resolvedAsset);

        $this->assertSame('https://tenant-alpha.test/icon/icon-192x192.png?v=tenant-pwa-icon', $resolvedAsset);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_asset_response_resolves_landlord_canonical_favicon_for_tenant_fallback(): void
    {
        Storage::fake('public');

        $tenant = Tenant::query()->firstOrFail();
        $tenant->branding_data = [
            'logo_settings' => [
                'favicon_uri' => '',
            ],
            'pwa_icon' => [
                'icon192_uri' => '',
                'icon512_uri' => '',
                'source_uri' => '',
                'icon_maskable512_uri' => '',
            ],
        ];
        $tenant->save();
        $tenant->fresh()?->makeCurrent();

        $landlord = Landlord::singleton();
        Storage::disk('public')->put('landlord/logos/favicon.ico', 'landlord-favicon');

        $landlord->branding_data = array_replace_recursive(
            is_array($landlord->branding_data) ? $landlord->branding_data : [],
            [
                'logo_settings' => [
                    'favicon_uri' => 'https://belluga.test/favicon.ico?v=landlord-favicon',
                ],
            ],
        );
        $landlord->save();

        $resolvedAsset = $this->service->resolveFaviconAsset();
        $response = $this->service->assetResponse($resolvedAsset);

        $this->assertSame('https://belluga.test/favicon.ico?v=landlord-favicon', $resolvedAsset);
        $this->assertSame(200, $response->getStatusCode());
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
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-alpha.test']
        );

        $service->initialize($payload);
    }
}
