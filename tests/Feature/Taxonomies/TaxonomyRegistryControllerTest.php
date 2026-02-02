<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomies;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TaxonomyRegistryControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->first();
        if ($tenant) {
            $tenant->makeCurrent();
        }
    }

    public function testTaxonomyCrudFlow(): void
    {
        $created = $this->postJson(
            "{$this->base_tenant_api_admin}taxonomies",
            [
                'slug' => 'cuisine',
                'name' => 'Cuisine',
                'applies_to' => ['account_profile', 'static_asset', 'event'],
                'icon' => 'mode_subscription',
                'color' => '#FFAA00',
            ],
            $this->getHeaders()
        );

        $created->assertStatus(201);
        $taxonomyId = $created->json('data.id');
        $this->assertNotEmpty($taxonomyId);

        $list = $this->getJson("{$this->base_tenant_api_admin}taxonomies", $this->getHeaders());
        $list->assertStatus(200);
        $this->assertNotEmpty($list->json('data'));

        $updated = $this->patchJson(
            "{$this->base_tenant_api_admin}taxonomies/{$taxonomyId}",
            [
                'name' => 'Cuisine Updated',
                'icon' => 'restaurant',
                'color' => '#00AAFF',
                'applies_to' => ['account_profile', 'event'],
            ],
            $this->getHeaders()
        );

        $updated->assertStatus(200);
        $updated->assertJsonPath('data.name', 'Cuisine Updated');

        $termCreated = $this->postJson(
            "{$this->base_tenant_api_admin}taxonomies/{$taxonomyId}/terms",
            [
                'slug' => 'italian',
                'name' => 'Italian',
            ],
            $this->getHeaders()
        );

        $termCreated->assertStatus(201);
        $termId = $termCreated->json('data.id');
        $this->assertNotEmpty($termId);

        $termUpdated = $this->patchJson(
            "{$this->base_tenant_api_admin}taxonomies/{$taxonomyId}/terms/{$termId}",
            [
                'name' => 'Italian Updated',
            ],
            $this->getHeaders()
        );

        $termUpdated->assertStatus(200);
        $termUpdated->assertJsonPath('data.name', 'Italian Updated');

        $termDeleted = $this->deleteJson(
            "{$this->base_tenant_api_admin}taxonomies/{$taxonomyId}/terms/{$termId}",
            [],
            $this->getHeaders()
        );

        $termDeleted->assertStatus(200);

        $deleted = $this->deleteJson(
            "{$this->base_tenant_api_admin}taxonomies/{$taxonomyId}",
            [],
            $this->getHeaders()
        );

        $deleted->assertStatus(200);
    }

    public function testTaxonomyRequiresValidColor(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}taxonomies",
            [
                'slug' => 'invalid-color',
                'name' => 'Invalid Color',
                'applies_to' => ['account_profile'],
                'color' => '#GGGGGG',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);
    }
}
