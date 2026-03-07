<?php

namespace Tests\Api\v1\Tenants\Branding;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings as AppTenantSettings;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Tests\TestCaseTenant;
use Tests\Helpers\TenantLabels;

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

    public function testEnvironmentApiReturnsTenantPayload(): void
    {
        $tenant = $this->currentTenant();
        $tenant->makeCurrent();

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
            'telemetry',
        ]);
        $response->assertJsonPath('type', 'tenant');
        $this->assertSame(
            parse_url($tenant->getMainDomain(), PHP_URL_HOST),
            parse_url((string) $response->json('main_domain'), PHP_URL_HOST)
        );
        $response->assertJsonPath('telemetry.location_freshness_minutes', 5);
    }

    public function testEnvironmentApiFallsBackToSubdomainWhenNoDomains(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->domains()->delete();
        $tenant->domains = [];
        $tenant->save();
        $tenant->makeCurrent();

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $this->assertSame(
            parse_url($tenant->getMainDomain(), PHP_URL_HOST),
            parse_url((string) $response->json('main_domain'), PHP_URL_HOST)
        );
    }

    public function testEnvironmentApiPrefersFirstRelatedDomainWhenNoMainFlagExists(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $tenant->domains()->delete();
        $tenant->domains()->create([
            'path' => 'custom-tenant-main.test',
            'type' => 'web',
        ]);
        $tenant->makeCurrent();

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $this->assertSame(
            'custom-tenant-main.test',
            parse_url((string) $response->json('main_domain'), PHP_URL_HOST)
        );
        $this->assertSame(
            'custom-tenant-main.test',
            parse_url($tenant->getMainDomain(), PHP_URL_HOST)
        );
    }

    public function testEnvironmentApiIgnoresLegacyPersistedLandlordFallbackDomains(): void
    {
        $tenant = $this->currentTenant();
        $this->snapshotTenant($tenant);
        $rootHost = $this->rootHost();

        $tenant->update(['subdomain' => 'guarappari']);
        $tenant->domains()->delete();
        $tenant->domains()->create([
            'path' => "guarapari.$rootHost",
            'type' => 'web',
        ]);
        $tenant->makeCurrent();

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $this->assertSame(
            "guarappari.$rootHost",
            parse_url((string) $response->json('main_domain'), PHP_URL_HOST)
        );
        $this->assertSame(
            ["guarappari.$rootHost"],
            $response->json('domains')
        );
    }

    public function testEnvironmentApiUsesTelemetryFromSettingsKernel(): void
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

    public function testEnvironmentApiExposesMapUiDefaultOriginFromSettings(): void
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
            ],
        ]);

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonPath('settings.map_ui.default_origin.lat', -20.671339);
        $response->assertJsonPath('settings.map_ui.default_origin.lng', -40.495395);
        $response->assertJsonPath('settings.map_ui.default_origin.label', 'Praia do Morro');
    }

    private function currentTenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
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
        $tenant->domains()->delete();

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
