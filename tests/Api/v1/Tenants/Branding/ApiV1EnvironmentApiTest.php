<?php

namespace Tests\Api\v1\Tenants\Branding;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantSettings as AppTenantSettings;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Tests\TestCaseTenant;
use Tests\Helpers\TenantLabels;

class ApiV1EnvironmentApiTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
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

}
