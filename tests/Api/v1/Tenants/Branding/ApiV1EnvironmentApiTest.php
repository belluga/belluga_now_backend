<?php

namespace Tests\Api\v1\Tenants\Branding;

use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings as AppTenantSettings;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class ApiV1EnvironmentApiTest extends TestCaseTenant
{
    /** @var array<string, mixed>|null */
    private ?array $tenantSnapshot = null;

    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    protected function tearDown(): void
    {
        $this->restoreTenantSnapshot();

        parent::tearDown();
    }

    public function test_environment_api_returns_tenant_payload(): void
    {
        $tenant = $this->currentTenant();
        $tenant->makeCurrent();
        $tenantRequestHost = parse_url($this->base_tenant_url, PHP_URL_HOST);
        $this->assertIsString($tenantRequestHost);

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'type',
            'tenant_id',
            'name',
            'subdomain',
            'main_domain',
            'landlord_domain',
            'domains',
            'app_domains',
            'theme_data_settings',
            'branding_assets' => [
                'favicon' => [
                    'has_dedicated_asset',
                    'uses_pwa_fallback',
                ],
            ],
            'public_web_metadata' => [
                'default_title',
                'default_description',
                'default_image',
            ],
            'telemetry',
        ]);
        $response->assertJsonPath('type', 'tenant');
        $this->assertSame(
            $tenantRequestHost,
            parse_url((string) $response->json('main_domain'), PHP_URL_HOST)
        );
        $response->assertJsonPath('telemetry.location_freshness_minutes', 5);
    }

    public function test_environment_api_exposes_when_favicon_route_has_dedicated_asset(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->makeCurrent();

        $tenantBranding = $tenant->branding_data ?? [];
        $tenantBranding['logo_settings']['favicon_uri'] = 'https://tenant-sigma.test/storage/tenant-favicon.ico';
        $tenant->branding_data = $tenantBranding;
        $tenant->save();

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonPath('branding_assets.favicon.has_dedicated_asset', true);
        $response->assertJsonPath('branding_assets.favicon.uses_pwa_fallback', false);
    }

    public function test_environment_api_exposes_public_web_metadata_from_merged_branding(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->makeCurrent();

        $landlord = Landlord::singleton();
        $originalLandlordBranding = $landlord->branding_data ?? [];

        $landlordBranding = $originalLandlordBranding;
        $landlordBranding['public_web_metadata'] = [
            'default_title' => 'Belluga fallback',
            'default_description' => 'Descricao institucional do landlord.',
            'default_image' => 'https://landlord.example/meta/default.jpg',
        ];
        $landlord->branding_data = $landlordBranding;
        $landlord->save();

        $tenantBranding = $tenant->branding_data ?? [];
        $tenantBranding['public_web_metadata'] = [
            'default_title' => 'Guarappari fallback',
            'default_description' => 'Descricao institucional do tenant.',
            'default_image' => 'https://tenant.example/meta/default.jpg',
        ];
        $tenant->branding_data = $tenantBranding;
        $tenant->save();

        try {
            $response = $this->get("{$this->base_api_tenant}environment");

            $response->assertStatus(200);
            $response->assertJsonPath('public_web_metadata.default_title', 'Guarappari fallback');
            $response->assertJsonPath('public_web_metadata.default_description', 'Descricao institucional do tenant.');
            $response->assertJsonPath('public_web_metadata.default_image', 'https://tenant.example/meta/default.jpg');
        } finally {
            $landlord->branding_data = $originalLandlordBranding;
            $landlord->save();
        }
    }

    public function test_environment_api_rewrites_internal_public_web_default_image_to_current_tenant_host(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->makeCurrent();

        $tenantOrigin = rtrim($this->base_tenant_url, '/');
        $tenantBranding = $tenant->branding_data ?? [];
        $tenantBranding['public_web_metadata'] = [
            'default_title' => 'Guarappari fallback',
            'default_description' => 'Descricao institucional do tenant.',
            'default_image' => "https://belluga.space/storage/tenants/{$tenant->slug}/public-web/default-image.jpg",
        ];
        $tenant->branding_data = $tenantBranding;
        $tenant->save();

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $defaultImage = (string) $response->json('public_web_metadata.default_image');
        $this->assertStringContainsString(
            "{$tenantOrigin}/api/v1/media/branding-public-web/{$tenant->_id}/default_image",
            $defaultImage
        );
        $this->assertStringContainsString('?v=', $defaultImage);
    }

    public function test_environment_api_does_not_inherit_landlord_public_web_metadata_when_tenant_has_no_override(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->makeCurrent();

        $landlord = Landlord::singleton();
        $originalLandlordBranding = $landlord->branding_data ?? [];

        $landlordBranding = $originalLandlordBranding;
        $landlordBranding['public_web_metadata'] = [
            'default_title' => 'Belluga fallback',
            'default_description' => 'Descricao institucional do landlord.',
            'default_image' => 'https://landlord.example/meta/default.jpg',
        ];
        $landlord->branding_data = $landlordBranding;
        $landlord->save();

        $tenantBranding = $tenant->branding_data ?? [];
        unset($tenantBranding['public_web_metadata']);
        $tenant->branding_data = $tenantBranding;
        $tenant->save();

        try {
            $response = $this->get("{$this->base_api_tenant}environment");

            $response->assertStatus(200);
            $response->assertJsonPath('public_web_metadata.default_title', '');
            $response->assertJsonPath('public_web_metadata.default_description', '');
            $response->assertJsonPath('public_web_metadata.default_image', '');
        } finally {
            $landlord->branding_data = $originalLandlordBranding;
            $landlord->save();
        }
    }

    public function test_environment_api_exposes_when_favicon_route_is_using_pwa_fallback(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->makeCurrent();

        $landlord = Landlord::singleton();
        $originalLandlordBranding = $landlord->branding_data ?? [];

        $tenantBranding = $tenant->branding_data ?? [];
        $tenantBranding['logo_settings']['favicon_uri'] = '';
        $tenantBranding['pwa_icon']['icon192_uri'] = 'https://tenant-sigma.test/storage/tenant-pwa-192.png';
        $tenant->branding_data = $tenantBranding;
        $tenant->save();

        $landlordBranding = $landlord->branding_data ?? [];
        $landlordBranding['logo_settings']['favicon_uri'] = '';
        $landlord->branding_data = $landlordBranding;
        $landlord->save();

        try {
            $response = $this->get("{$this->base_api_tenant}environment");

            $response->assertStatus(200);
            $response->assertJsonPath('branding_assets.favicon.has_dedicated_asset', false);
            $response->assertJsonPath('branding_assets.favicon.uses_pwa_fallback', true);
        } finally {
            $landlord->branding_data = $originalLandlordBranding;
            $landlord->save();
        }
    }

    public function test_environment_api_falls_back_to_subdomain_when_no_domains(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->domains()->withTrashed()->forceDelete();
        $tenant->domains = [];
        $tenant->save();
        $tenant->makeCurrent();
        $tenantRequestHost = parse_url($this->base_tenant_url, PHP_URL_HOST);
        $this->assertIsString($tenantRequestHost);

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $this->assertSame(
            $tenantRequestHost,
            parse_url((string) $response->json('main_domain'), PHP_URL_HOST)
        );
    }

    public function test_environment_api_on_subdomain_request_keeps_current_subdomain_without_projecting_it_into_domains(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->domains()->withTrashed()->forceDelete();
        $tenant->domains()->create([
            'path' => 'custom-tenant-main.test',
            'type' => 'web',
        ]);
        $tenant->makeCurrent();

        $subdomainHost = parse_url($this->base_tenant_url, PHP_URL_HOST);
        $this->assertIsString($subdomainHost);

        $response = $this->get("http://{$subdomainHost}/api/v1/environment");

        $response->assertStatus(200);
        $this->assertSame(
            $subdomainHost,
            parse_url((string) $response->json('main_domain'), PHP_URL_HOST)
        );
        $this->assertSame(['custom-tenant-main.test'], $response->json('domains', []));
    }

    public function test_environment_api_on_custom_domain_request_uses_current_custom_domain_and_keeps_domains_explicit_only(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->domains()->withTrashed()->forceDelete();
        $tenant->domains()->create([
            'path' => 'custom-tenant-main.test',
            'type' => 'web',
        ]);
        $tenant->makeCurrent();

        $subdomainHost = parse_url($this->base_tenant_url, PHP_URL_HOST);
        $this->assertIsString($subdomainHost);

        $response = $this->get('http://custom-tenant-main.test/api/v1/environment');

        $response->assertStatus(200);
        $this->assertSame(
            'custom-tenant-main.test',
            parse_url((string) $response->json('main_domain'), PHP_URL_HOST)
        );
        $this->assertSame(['custom-tenant-main.test'], $response->json('domains', []));
    }

    public function test_environment_api_ignores_legacy_persisted_landlord_fallback_domains(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $rootHost = $this->rootHost();
        $canonicalSubdomain = $tenant->subdomain;
        $legacyFallbackDomain = "{$canonicalSubdomain}-legacy.$rootHost";

        $tenant->domains()->withTrashed()->forceDelete();
        $tenant->domains()->create([
            'path' => $legacyFallbackDomain,
            'type' => 'web',
        ]);
        $tenant->makeCurrent();

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonPath('type', 'tenant');
        $this->assertSame(
            "{$canonicalSubdomain}.$rootHost",
            parse_url((string) $response->json('main_domain'), PHP_URL_HOST)
        );
        $this->assertSame([], $response->json('domains'));
    }

    public function test_environment_api_uses_telemetry_from_settings_kernel(): void
    {
        $tenant = $this->currentTenant();
        $tenant->makeCurrent();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'telemetry' => [
                'location_freshness_minutes' => 7,
                'trackers' => [
                    [
                        'type' => 'mixpanel',
                        'token' => 'kernel-token',
                        'events' => ['invite_received'],
                    ],
                ],
            ],
        ]);

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonPath('telemetry.location_freshness_minutes', 7);
        $response->assertJsonPath('telemetry.trackers.0.type', 'mixpanel');
        $response->assertJsonPath('telemetry.trackers.0.token', 'kernel-token');
        $response->assertJsonPath('telemetry.trackers.0.events.0', 'invite_received');
    }

    public function test_environment_api_exposes_tenant_public_auth_from_persisted_settings(): void
    {
        $tenant = $this->currentTenant();
        $tenant->makeCurrent();

        $landlord = LandlordSettings::current();
        $originalLandlordAuth = $landlord?->getAttribute('tenant_public_auth');
        if ($landlord === null) {
            $landlord = new LandlordSettings();
            $landlord->setAttribute('_id', 'settings_root');
        }

        $tenantSettings = TenantSettings::current();
        $originalTenantAuth = $tenantSettings?->getAttribute('tenant_public_auth');
        if ($tenantSettings === null) {
            $tenantSettings = new TenantSettings();
            $tenantSettings->setAttribute('_id', 'settings_root');
        }

        $landlord->setAttribute('tenant_public_auth', [
            'available_methods' => ['password', 'phone_otp'],
            'allow_tenant_customization' => true,
        ]);
        $tenantSettings->setAttribute('tenant_public_auth', [
            'enabled_methods' => ['phone_otp'],
        ]);
        $landlord->save();
        $tenantSettings->save();

        try {
            $response = $this->get("{$this->base_api_tenant}environment");

            $response->assertStatus(200);
            $response->assertJsonPath('settings.tenant_public_auth.available_methods.0', 'password');
            $response->assertJsonPath('settings.tenant_public_auth.available_methods.1', 'phone_otp');
            $response->assertJsonPath('settings.tenant_public_auth.enabled_methods.0', 'phone_otp');
            $response->assertJsonPath('settings.tenant_public_auth.effective_methods.0', 'phone_otp');
            $response->assertJsonPath('settings.tenant_public_auth.effective_primary_method', 'phone_otp');
        } finally {
            $landlord->setAttribute('tenant_public_auth', $originalLandlordAuth);
            $landlord->save();
            $tenantSettings->setAttribute('tenant_public_auth', $originalTenantAuth);
            $tenantSettings->save();
        }
    }

    public function test_environment_api_exposes_map_ui_default_origin_from_settings(): void
    {
        $tenant = $this->currentTenant();
        $tenant->makeCurrent();

        AppTenantSettings::query()->delete();
        AppTenantSettings::create([
            'map_ui' => [
                'radius' => [
                    'min_km' => 1,
                    'default_km' => 5,
                    'max_km' => 50,
                ],
                'default_origin' => [
                    'lat' => -20.671339,
                    'lng' => -40.495395,
                    'label' => 'Praia do Morro',
                ],
                'filters' => [
                    [
                        'key' => 'event',
                        'label' => 'Eventos',
                        'image_uri' => 'https://tenant-alpha.test/storage/map-filters/event.png',
                    ],
                ],
            ],
        ]);

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonPath('settings.map_ui.default_origin.lat', -20.671339);
        $response->assertJsonPath('settings.map_ui.default_origin.lng', -40.495395);
        $response->assertJsonPath('settings.map_ui.default_origin.label', 'Praia do Morro');
        $response->assertJsonPath('settings.map_ui.filters.0.key', 'event');
        $response->assertJsonPath('settings.map_ui.filters.0.label', 'Eventos');
        $response->assertJsonPath(
            'settings.map_ui.filters.0.image_uri',
            'https://tenant-alpha.test/storage/map-filters/event.png'
        );
    }

    private function currentTenant(): Tenant
    {
        return $this->resolveCanonicalTenant($this->tenant);
    }

    private function snapshotTenant(Tenant $tenant): void
    {
        if ($this->tenantSnapshot !== null) {
            return;
        }

        $this->tenantSnapshot = [
            'id' => (string) $tenant->getKey(),
            'subdomain' => $tenant->subdomain,
        ];
    }

    private function restoreTenantSnapshot(): void
    {
        if ($this->tenantSnapshot === null) {
            return;
        }

        $tenant = Tenant::query()->findOrFail($this->tenantSnapshot['id']);
        $tenant->update([
            'subdomain' => $this->tenantSnapshot['subdomain'],
        ]);
        $tenant->domains()->withTrashed()->forceDelete();

        $this->tenantSnapshot = null;
    }

    private function rootHost(): string
    {
        $configuredUrl = (string) config('app.url');
        $rootHost = parse_url($configuredUrl, PHP_URL_HOST);
        if (is_string($rootHost) && $rootHost !== '') {
            return $rootHost;
        }

        return trim(str_replace(['https://', 'http://'], '', $configuredUrl), '/');
    }
}
