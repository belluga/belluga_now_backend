<?php

namespace Tests\Api\v1\Tenants\Branding;

use App\Models\Landlord\Tenant;
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

}