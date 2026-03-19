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
        $existingAndroid = $this->tenantModel->domains()
            ->where('type', Tenant::DOMAIN_TYPE_APP_ANDROID)
            ->first();

        if ($existingAndroid === null) {
            $this->tenantModel->domains()->create([
                'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
                'path' => 'tenanttheta.app',
            ]);
        } else {
            $existingAndroid->path = 'tenanttheta.app';
            $existingAndroid->save();
        }
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
            'app_domains' => [
                'android' => 'tenanttheta.app',
                'ios' => null,
            ],
        ]);
    }

    public function test_store_upserts_domain_for_platform(): void
    {
        $response = $this->withHeaders($this->headers)->postJson($this->baseUrl, [
            'platform' => 'android',
            'identifier' => 'tenanttheta.mobile',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'App domain identifier saved successfully.',
            'app_domains' => [
                'android' => 'tenanttheta.mobile',
                'ios' => null,
            ],
        ]);
    }

    public function test_store_sets_ios_identifier(): void
    {
        $response = $this->withHeaders($this->headers)->postJson($this->baseUrl, [
            'platform' => 'ios',
            'identifier' => 'com.boora.tenanttheta',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'App domain identifier saved successfully.',
            'app_domains' => [
                'android' => 'tenanttheta.app',
                'ios' => 'com.boora.tenanttheta',
            ],
        ]);
    }

    public function test_destroy_removes_domain_for_platform(): void
    {
        $this->upsertTypedAppDomain(Tenant::DOMAIN_TYPE_APP_IOS, 'com.boora.tenanttheta');

        $response = $this->withHeaders($this->headers)->deleteJson($this->baseUrl, [
            'platform' => 'ios',
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => 'App domain identifier removed successfully.',
            'app_domains' => [
                'android' => 'tenanttheta.app',
                'ios' => null,
            ],
        ]);
    }

    private function upsertTypedAppDomain(string $type, string $identifier): void
    {
        $existing = $this->tenantModel->domains()
            ->where('type', $type)
            ->first();

        if ($existing === null) {
            $this->tenantModel->domains()->create([
                'type' => $type,
                'path' => $identifier,
            ]);

            return;
        }

        $existing->path = $identifier;
        $existing->save();
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
