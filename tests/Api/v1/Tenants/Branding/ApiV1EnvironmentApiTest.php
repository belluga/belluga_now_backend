<?php

namespace Tests\Api\v1\Tenants\Branding;

use App\Models\Landlord\Tenant;
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
        $this->currentTenant()->makeCurrent();

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'type',
            'tenant_id',
            'name',
            'subdomain',
            'main_domain',
            'domains',
            'app_domains',
            'theme_data_settings',
            'telemetry',
        ]);
        $response->assertJsonPath('type', 'tenant');
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
        $response->assertJsonPath(
            'main_domain',
            "http://{$this->tenant->subdomain}.{$this->host}"
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

    private function currentTenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }

}
