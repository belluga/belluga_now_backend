<?php

namespace Tests\Api\v1\Tenants\Branding;

use App\Models\Landlord\Tenant;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
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
        ]);
        $response->assertJsonPath('type', 'tenant');
    }

    public function testEnvironmentApiFallsBackToSubdomainWhenNoDomains(): void
    {
        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->first();
        $this->assertNotNull($tenant);
        $tenant->domains()->delete();
        $tenant->domains = [];
        $tenant->save();

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonPath(
            'main_domain',
            "https://{$this->tenant->subdomain}.{$this->host}"
        );
    }

    public function testEnvironmentApiNormalizesLegacyTelemetry(): void
    {
        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        TenantPushSettings::query()->delete();
        TenantPushSettings::create([
            'push' => [
                'max_ttl_days' => 30,
                'message_types' => [
                    [
                        'key' => 'invite_received',
                        'label' => 'Invite Received',
                    ],
                ],
            ],
            'telemetry' => [
                'mixpanel_token' => 'legacy-token',
                'enabled_events' => ['invite_received'],
            ],
        ]);

        $response = $this->get("{$this->base_api_tenant}environment");

        $response->assertStatus(200);
        $response->assertJsonPath('telemetry.0.type', 'mixpanel');
        $response->assertJsonPath('telemetry.0.token', 'legacy-token');
        $response->assertJsonPath('telemetry.0.events.0', 'invite_received');
    }

}
