<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantAppDomainControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Tenant $tenantModel;

    private array $headers;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenantModel = Tenant::query()->firstOrFail();
        $this->tenantModel->update(['app_domains' => ['tenanttheta.app']]);
        $this->tenantModel->makeCurrent();
        $this->baseUrl = "{$this->base_tenant_api_admin}appdomains";

        $this->headers = array_merge($this->getHeaders(), [
            'X-App-Domain' => 'tenanttheta.app',
        ]);
    }

    public function test_index_returns_tenant_app_domains(): void
    {
        $response = $this->withHeaders($this->headers)->getJson($this->baseUrl);

        $response->assertOk();
        $response->assertJson([
            'app_domains' => ['tenanttheta.app'],
        ]);
    }

    public function test_store_appends_domain(): void
    {
        $response = $this->withHeaders($this->headers)->postJson($this->baseUrl, [
            'app_domain' => 'tenanttheta.mobile',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'App domains added successfully.',
            'app_domains' => ['tenanttheta.app', 'tenanttheta.mobile'],
        ]);
    }

    public function test_destroy_removes_domain(): void
    {
        $this->tenantModel->update(['app_domains' => ['tenanttheta.app', 'removethis.app']]);

        $response = $this->withHeaders($this->headers)->deleteJson($this->baseUrl, [
            'app_domain' => 'removethis.app',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'App domains deleted successfully.',
            'app_domains' => ['tenanttheta.app'],
        ]);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Theta', 'subdomain' => 'tenant-theta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-theta.test']
        );

        $service->initialize($payload);
    }
}
