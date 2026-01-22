<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Helpers\TenantLabels;

class TenantDomainControllerTest extends TestCaseTenant
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
        $this->tenantModel->update([
            'app_domains' => ['tenantkappa.app'],
        ]);
        $this->tenantModel->domains()->updateOrCreate(
            ['path' => 'tenantkappa.test'],
            ['type' => 'web']
        );
        $this->tenantModel = $this->tenantModel->fresh();
        $this->tenantModel->makeCurrent();
        $this->baseUrl = "{$this->base_tenant_api_admin}domains";

        $this->headers = array_merge($this->getHeaders(), [
            'X-App-Domain' => 'tenantkappa.app',
        ]);
    }

    public function testStoreCreatesDomain(): void
    {
        $response = $this->withHeaders($this->headers)->postJson($this->baseUrl, [
            'path' => 'tenantkappa.com',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'path',
                'type',
                'created_at',
            ],
        ]);
    }

    public function testDestroySoftDeletesDomain(): void
    {
        $domain = $this->tenantModel->domains()->create([
            'path' => 'removekappa.com',
            'type' => 'web',
        ]);
        $response = $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s', $this->baseUrl, $domain->_id));

        $response->assertOk();
        $this->assertSoftDeleted('domains', ['_id' => $domain->_id], 'landlord');
    }

    public function testRestoreBringsBackDomain(): void
    {
        $domain = $this->tenantModel->domains()->create([
            'path' => 'restorekappa.com',
            'type' => 'web',
        ]);
        $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s', $this->baseUrl, $domain->_id));

        $response = $this->withHeaders($this->headers)
            ->postJson(sprintf('%s/%s/restore', $this->baseUrl, $domain->_id));

        $response->assertOk();
        $response->assertJsonPath('data.path', 'restorekappa.com');
    }

    public function testForceDeleteRemovesDomain(): void
    {
        $domain = $this->tenantModel->domains()->create([
            'path' => 'forcekappa.com',
            'type' => 'web',
        ]);
        $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s', $this->baseUrl, $domain->_id));

        $response = $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s/force-delete', $this->baseUrl, $domain->_id));

        $response->assertOk();
        $this->assertDatabaseMissing('domains', ['_id' => $domain->_id], 'landlord');
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Kappa', 'subdomain' => 'tenant-kappa'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenantkappa.test']
        );

        $service->initialize($payload);
    }
}
