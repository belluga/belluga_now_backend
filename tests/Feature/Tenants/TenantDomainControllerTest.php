<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantDomainControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

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

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->update([
            'app_domains' => ['tenantkappa.app'],
        ]);
        $this->tenant->domains()->updateOrCreate(
            ['path' => 'tenantkappa.test'],
            ['type' => 'web']
        );
        $this->tenant = $this->tenant->fresh();
        $this->tenant->makeCurrent();
        $tenantHost = "{$this->tenant->subdomain}.{$this->host}";
        $this->baseUrl = "http://{$tenantHost}/admin/api/v1/domains";

        $this->headers = [
            'X-App-Domain' => 'tenantkappa.app',
        ];
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
        $domain = $this->tenant->domains()->create([
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
        $domain = $this->tenant->domains()->create([
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
        $domain = $this->tenant->domains()->create([
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
