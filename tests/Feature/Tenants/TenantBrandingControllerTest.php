<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantBrandingControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

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

        Tenant::query()->firstOrFail()->makeCurrent();
        $this->baseUrl = "{$this->base_tenant_api_admin}branding/update";
        $this->headers = $this->getHeaders();
        unset($this->headers['Content-Type']);
        $this->headers['X-App-Domain'] = 'tenant-sigma.test';
    }

    public function test_update_persists_branding_data(): void
    {
        $payload = [
            'theme_data_settings' => [
                'brightness_default' => 'light',
                'primary_seed_color' => '#ffffff',
                'secondary_seed_color' => '#eeeeee',
            ],
        ];

        $response = $this->withHeaders($this->headers)
            ->postJson($this->baseUrl, $payload);

        $response->assertOk();
        $tenant = Tenant::query()->first()->fresh();
        $this->assertSame(
            '#ffffff',
            $tenant->branding_data['theme_data_settings']['primary_seed_color']
        );
    }

    public function test_update_stores_uploaded_logos(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('light_logo.png', 120, 40);

        $response = $this->withHeaders($this->headers)
            ->post($this->baseUrl, [
                'logo_settings' => [
                    'light_logo_uri' => $file,
                ],
            ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('branding_data.logo_settings.light_logo_uri'));
    }

    public function test_update_persists_name_and_reflects_public_branding_metadata(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $originalSlug = (string) $tenant->slug;

        $response = $this->withHeaders($this->headers)
            ->postJson($this->baseUrl, [
                'name' => 'Guarappari',
            ]);

        $response->assertOk();

        $freshTenant = $tenant->fresh();

        $this->assertSame('Guarappari', $freshTenant?->name);
        $this->assertSame('Guarappari', $freshTenant?->short_name);
        $this->assertSame($originalSlug, $freshTenant?->slug);

        $this->withoutHeader('X-App-Domain')
            ->getJson("{$this->base_api_tenant}environment")
            ->assertOk()
            ->assertJsonPath('name', 'Guarappari');

        $manifestResponse = $this->get("{$this->base_tenant_url}manifest.json");

        $manifestResponse
            ->assertOk()
            ->assertJsonPath('name', 'Guarappari')
            ->assertJsonPath('short_name', 'Guarappari');

        $manifestCacheControl = (string) $manifestResponse->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $manifestCacheControl);
        $this->assertStringContainsString('no-cache', $manifestCacheControl);
        $this->assertStringContainsString('must-revalidate', $manifestCacheControl);
        $this->assertStringContainsString('max-age=0', $manifestCacheControl);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Sigma', 'subdomain' => 'tenant-sigma'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-sigma.test']
        );

        $service->initialize($payload);
    }
}
