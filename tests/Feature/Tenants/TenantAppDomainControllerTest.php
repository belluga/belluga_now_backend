<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantAppDomainControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->update(['app_domains' => ['tenanttheta.app']]);
        $this->tenant->makeCurrent();

        $this->headers = [
            'X-App-Domain' => 'tenanttheta.app',
        ];
    }

    public function testIndexReturnsTenantAppDomains(): void
    {
        $response = $this->withHeaders($this->headers)->getJson('api/v1/appdomains');

        $response->assertOk();
        $response->assertJson([
            'app_domains' => ['tenanttheta.app'],
        ]);
    }

    public function testStoreAppendsDomain(): void
    {
        $response = $this->withHeaders($this->headers)->postJson('api/v1/appdomains', [
            'app_domain' => 'tenanttheta.mobile',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'App domains added successfully.',
            'app_domains' => ['tenanttheta.app', 'tenanttheta.mobile'],
        ]);
    }

    public function testDestroyRemovesDomain(): void
    {
        $this->tenant->update(['app_domains' => ['tenanttheta.app', 'removethis.app']]);

        $response = $this->withHeaders($this->headers)->deleteJson('api/v1/appdomains', [
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
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-theta.test']
        );

        $service->initialize($payload);
    }
}
