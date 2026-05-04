<?php

namespace Tests\Api\v1\Tenants\Branding;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\Auth\TenantPublicAuthMethodResolver;
use App\Application\Branding\BrandingPublicWebMediaService;
use App\Application\Environment\TenantEnvironmentPayloadFactory;
use App\Application\Environment\TenantEnvironmentSnapshotService;
use App\Application\Telemetry\TelemetrySettingsKernelBridge;
use App\Models\Landlord\Landlord;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantEnvironmentSnapshot;
use App\Models\Tenants\TenantProfileType;
use App\Models\Tenants\TenantSettings as AppTenantSettings;
use Belluga\PushHandler\Services\PushSettingsKernelBridge;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
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

    public function test_environment_api_snapshot_matches_live_payload_on_tenant_subdomain_request(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->makeCurrent();
        $this->prepareEnvironmentParityFixture($tenant);

        $this->assertEnvironmentParity("{$this->base_api_tenant}environment");
    }

    public function test_environment_api_snapshot_matches_live_payload_on_custom_domain_request(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->makeCurrent();
        $this->prepareEnvironmentParityFixture($tenant);
        $tenant->domains()->withTrashed()->forceDelete();
        $tenant->domains()->create([
            'path' => 'custom-tenant-main.test',
            'type' => 'web',
        ]);

        $this->assertEnvironmentParity('http://custom-tenant-main.test/api/v1/environment');
    }

    public function test_environment_api_snapshot_matches_live_payload_for_landlord_host_app_domain_request(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->makeCurrent();
        $this->prepareEnvironmentParityFixture($tenant);
        Tenant::forgetCurrent();

        $appDomain = $tenant->resolvedAppDomains()[0] ?? null;
        $this->assertIsString($appDomain);
        $this->assertNotSame('', trim($appDomain));

        $this->assertEnvironmentParity(
            "http://{$this->host}/api/v1/environment",
            ['X-App-Domain' => $appDomain],
        );
    }

    public function test_environment_api_repairs_missing_snapshot_before_serving_payload(): void
    {
        $tenant = $this->currentTenant();
        $tenant->makeCurrent();
        $this->prepareEnvironmentParityFixture($tenant);

        TenantEnvironmentSnapshot::query()->delete();

        $url = "{$this->base_api_tenant}environment";
        $expected = $this->expectedEnvironmentContract($tenant, $url);
        $response = $this->get($url);

        $response->assertStatus(200);
        $this->assertSame($expected, $response->json());

        $snapshot = TenantEnvironmentSnapshot::current();
        $this->assertNotNull($snapshot);
        $this->assertSame(TenantEnvironmentSnapshotService::SCHEMA_VERSION, (int) $snapshot->schema_version);
        $this->assertNotSame('', (string) $snapshot->snapshot_version);
        $this->assertNotNull($snapshot->built_at);
    }

    public function test_environment_api_serves_last_valid_snapshot_when_repair_fails(): void
    {
        $tenant = $this->currentTenant();
        $tenant->makeCurrent();
        $this->prepareEnvironmentParityFixture($tenant);

        $url = "{$this->base_api_tenant}environment";
        $service = app(TenantEnvironmentSnapshotService::class);
        $service->repair($tenant, 'test_seed', ['case' => 'last_valid_fallback']);
        $baselinePayload = $this->expectedEnvironmentContract($tenant, $url);

        Queue::fake();

        $tenant->name = 'Changed Snapshot Name';
        $tenant->short_name = 'Changed Snapshot Name';
        $tenant->save();

        $snapshot = TenantEnvironmentSnapshot::current();
        $this->assertNotNull($snapshot);
        $snapshot->schema_version = 0;
        $snapshot->save();

        app()->instance(
            TenantEnvironmentPayloadFactory::class,
            new class(app(TelemetrySettingsKernelBridge::class), app(TenantPublicAuthMethodResolver::class), app(PushSettingsKernelBridge::class), app(AccountProfileRegistryService::class), app(BrandingPublicWebMediaService::class)) extends TenantEnvironmentPayloadFactory
            {
                public function buildSnapshotSource(Tenant $tenant): array
                {
                    throw new \RuntimeException('forced snapshot rebuild failure');
                }
            }
        );
        app()->forgetInstance(TenantEnvironmentSnapshotService::class);

        $response = $this->get($url);

        $response->assertStatus(200);
        $this->assertSame($baselinePayload, $response->json());

        $failedSnapshot = TenantEnvironmentSnapshot::current();
        $this->assertNotNull($failedSnapshot?->last_rebuild_failed_at);
        $this->assertStringContainsString(
            'forced snapshot rebuild failure',
            (string) $failedSnapshot?->last_rebuild_error
        );
    }

    public function test_dispatch_refresh_for_specific_tenant_preserves_current_tenant_context(): void
    {
        $primaryTenant = $this->currentTenant();

        $secondaryTenant = Tenant::query()
            ->where('_id', '!=', $primaryTenant->getKey())
            ->first();
        $createdSecondaryTenant = false;

        if (! $secondaryTenant instanceof Tenant) {
            $secondaryTenant = Tenant::create([
                'name' => 'Environment Snapshot Secondary',
                'subdomain' => 'environment-snapshot-secondary',
                'app_domains' => ['com.environment.snapshot.secondary'],
            ]);
            $createdSecondaryTenant = true;
        }

        try {
            $primaryTenant->makeCurrent();

            app(TenantEnvironmentSnapshotService::class)->dispatchRefreshForTenant(
                $secondaryTenant,
                'test_preserve_current_tenant_context',
            );

            $contextKey = (string) config('multitenancy.current_tenant_context_key', 'tenantId');

            $this->assertSame(
                (string) $primaryTenant->getKey(),
                (string) (Tenant::current()?->getKey() ?? ''),
            );
            $this->assertSame(
                (string) $primaryTenant->getKey(),
                trim((string) Context::get($contextKey, '')),
            );
        } finally {
            $primaryTenant->makeCurrent();

            if ($createdSecondaryTenant) {
                $secondaryTenant->domains()->withTrashed()->forceDelete();
                $secondaryTenant->forceDelete();
            }
        }
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
            $landlord = new LandlordSettings;
            $landlord->setAttribute('_id', 'settings_root');
        }

        $tenantSettings = TenantSettings::current();
        $originalTenantAuth = $tenantSettings?->getAttribute('tenant_public_auth');
        if ($tenantSettings === null) {
            $tenantSettings = new TenantSettings;
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

    public function test_environment_api_exposes_phone_otp_sms_fallback_flag_without_webhook_url(): void
    {
        $tenant = $this->currentTenant();
        $tenant->makeCurrent();

        TenantSettings::query()->delete();
        TenantSettings::create([
            'tenant_public_auth' => [
                'enabled_methods' => ['phone_otp'],
            ],
            'outbound_integrations' => [
                'whatsapp' => [
                    'webhook_url' => 'https://integrations.example/whatsapp',
                ],
                'otp' => [
                    'webhook_url' => 'https://integrations.example/sms',
                    'use_whatsapp_webhook' => true,
                    'delivery_channel' => 'whatsapp',
                    'ttl_minutes' => 10,
                    'resend_cooldown_seconds' => 60,
                    'max_attempts' => 5,
                ],
            ],
        ]);

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonPath('settings.tenant_public_auth.phone_otp.primary_channel', 'whatsapp');
        $response->assertJsonPath('settings.tenant_public_auth.phone_otp.sms_fallback_enabled', true);
        $this->assertArrayNotHasKey('outbound_integrations', $response->json('settings') ?? []);
        $this->assertStringNotContainsString('https://integrations.example/sms', (string) $response->getContent());
        $this->assertStringNotContainsString('https://integrations.example/whatsapp', (string) $response->getContent());
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

    public function test_environment_api_exposes_publication_app_links_from_settings(): void
    {
        $tenant = $this->currentTenant();
        $tenant->makeCurrent();

        AppTenantSettings::query()->delete();
        AppTenantSettings::create([
            'app_links' => [
                'android' => [
                    'enabled' => true,
                    'store_url' => 'https://play.google.com/store/apps/details?id=com.tenant.alpha',
                ],
                'ios' => [
                    'enabled' => false,
                    'store_url' => 'https://apps.apple.com/br/app/id123456789',
                ],
            ],
        ]);

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonPath('settings.app_links.android.enabled', true);
        $response->assertJsonPath('settings.app_links.android.store_url', 'https://play.google.com/store/apps/details?id=com.tenant.alpha');
        $response->assertJsonPath('settings.app_links.ios.enabled', false);
        $response->assertJsonPath('settings.app_links.ios.store_url', 'https://apps.apple.com/br/app/id123456789');
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
            'branding_data' => $tenant->branding_data,
        ];
    }

    private function restoreTenantSnapshot(): void
    {
        if ($this->tenantSnapshot === null) {
            return;
        }

        $tenant = Tenant::query()->findOrFail($this->tenantSnapshot['id']);
        $tenant->makeCurrent();
        $tenant->update([
            'subdomain' => $this->tenantSnapshot['subdomain'],
            'branding_data' => $this->tenantSnapshot['branding_data'],
        ]);
        $tenant->domains()->withTrashed()->forceDelete();
        Tenant::forgetCurrent();

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

    /**
     * @param  array<string, string>  $headers
     */
    private function assertEnvironmentParity(string $url, array $headers = []): void
    {
        $tenant = $this->currentTenant();
        $expected = $this->expectedEnvironmentContract($tenant, $url);
        $response = $this->withHeaders($headers)->get($url);

        $response->assertStatus(200);
        $this->assertSame($expected, $response->json());
    }

    private function expectedEnvironmentContract(Tenant $tenant, string $url): array
    {
        $tenant->makeCurrent();
        $requestRoot = $this->requestRootForUrl($url);
        $requestHost = parse_url($url, PHP_URL_HOST);
        $this->assertIsString($requestHost);

        $resolved = app(TenantEnvironmentPayloadFactory::class)->buildLiveTenantPayload(
            $tenant,
            $requestRoot,
            $requestHost,
        );

        return $this->filterEnvironmentContract($resolved);
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function filterEnvironmentContract(array $resolved): array
    {
        return [
            'type' => $resolved['type'] ?? null,
            'tenant_id' => $resolved['tenant_id'] ?? null,
            'name' => $resolved['name'] ?? null,
            'subdomain' => $resolved['subdomain'] ?? null,
            'main_domain' => $resolved['main_domain'] ?? null,
            'landlord_domain' => $resolved['landlord_domain'] ?? null,
            'domains' => $resolved['domains'] ?? [],
            'app_domains' => $resolved['app_domains'] ?? [],
            'theme_data_settings' => $resolved['theme_data_settings'] ?? [],
            'branding_assets' => $resolved['branding_assets'] ?? [],
            'public_web_metadata' => $resolved['public_web_metadata'] ?? [],
            'telemetry' => $resolved['telemetry'] ?? [],
            'firebase' => $resolved['firebase'] ?? [],
            'push' => $resolved['push'] ?? [],
            'profile_types' => $resolved['profile_types'] ?? [],
            'settings' => $resolved['settings'] ?? [],
        ];
    }

    private function requestRootForUrl(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        $this->assertIsString($scheme);
        $this->assertIsString($host);

        return sprintf(
            '%s://%s%s',
            $scheme,
            $host,
            is_int($port) ? ':'.$port : '',
        );
    }

    private function prepareEnvironmentParityFixture(Tenant $tenant): void
    {
        $this->snapshotTenant($tenant);

        $tenant->domains()->updateOrCreate(
            ['type' => Tenant::DOMAIN_TYPE_APP_ANDROID],
            ['path' => "com.{$tenant->slug}.android"],
        );
        $tenant->domains()->updateOrCreate(
            ['type' => Tenant::DOMAIN_TYPE_APP_IOS],
            ['path' => "com.{$tenant->slug}.ios"],
        );

        $tenantBranding = is_array($tenant->branding_data ?? null) ? $tenant->branding_data : [];
        $tenantBranding['public_web_metadata'] = [
            'default_title' => 'Environment Snapshot Fixture',
            'default_description' => 'Tenant metadata used for snapshot parity tests.',
            'default_image' => "https://belluga.space/storage/tenants/{$tenant->slug}/public-web/default-image.jpg",
        ];
        $tenant->branding_data = $tenantBranding;
        $tenant->save();

        AppTenantSettings::query()->updateOrCreate(
            ['_id' => AppTenantSettings::ROOT_ID],
            [
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
            ],
        );

        $profileType = TenantProfileType::query()->updateOrCreate(
            ['type' => 'restaurant'],
            [
                'label' => 'Restaurant',
                'labels' => [
                    'singular' => 'Restaurant',
                    'plural' => 'Restaurants',
                ],
                'allowed_taxonomies' => [],
                'visual' => [
                    'mode' => 'image',
                    'image_source' => 'type_asset',
                ],
                'poi_visual' => [
                    'mode' => 'image',
                    'image_source' => 'type_asset',
                ],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                    'has_bio' => true,
                    'has_content' => true,
                    'has_taxonomies' => true,
                    'has_avatar' => true,
                    'has_cover' => true,
                    'has_events' => true,
                ],
            ],
        );

        $profileType->type_asset_url = sprintf(
            'https://%s.%s/account-profile-types/%s/type_asset?v=123',
            $tenant->subdomain,
            $this->host,
            (string) $profileType->getKey(),
        );
        $profileType->save();
    }
}
