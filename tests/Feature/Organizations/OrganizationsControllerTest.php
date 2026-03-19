<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\Organization;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class OrganizationsControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

    }

    public function test_organization_create_and_show(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}organizations",
            [
                'name' => 'Org Alpha',
                'description' => 'Tenant org',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Org Alpha');

        $orgId = $response->json('data._id') ?? $response->json('data.id');
        $this->assertNotEmpty($orgId);

        $show = $this->getJson("{$this->base_tenant_api_admin}organizations/{$orgId}", $this->getHeaders());
        $show->assertStatus(200);
        $show->assertJsonPath('data.name', 'Org Alpha');
    }

    public function test_organization_create_validation(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}organizations",
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_organization_index_lists(): void
    {
        Organization::query()->delete();

        $this->postJson(
            "{$this->base_tenant_api_admin}organizations",
            ['name' => 'Org One'],
            $this->getHeaders()
        )->assertStatus(201);

        $this->postJson(
            "{$this->base_tenant_api_admin}organizations",
            ['name' => 'Org Two'],
            $this->getHeaders()
        )->assertStatus(201);

        $response = $this->getJson("{$this->base_tenant_api_admin}organizations", $this->getHeaders());
        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_organization_update(): void
    {
        $created = $this->postJson(
            "{$this->base_tenant_api_admin}organizations",
            ['name' => 'Org Update'],
            $this->getHeaders()
        );

        $created->assertStatus(201);
        $orgId = $created->json('data._id') ?? $created->json('data.id');

        $updated = $this->patchJson(
            "{$this->base_tenant_api_admin}organizations/{$orgId}",
            ['name' => 'Org Updated'],
            $this->getHeaders()
        );

        $updated->assertStatus(200);
        $updated->assertJsonPath('data.name', 'Org Updated');
    }

    public function test_organization_delete_restore(): void
    {
        $created = $this->postJson(
            "{$this->base_tenant_api_admin}organizations",
            ['name' => 'Org Delete'],
            $this->getHeaders()
        );

        $created->assertStatus(201);
        $orgId = $created->json('data._id') ?? $created->json('data.id');

        $this->deleteJson("{$this->base_tenant_api_admin}organizations/{$orgId}", [], $this->getHeaders())
            ->assertStatus(200);

        $this->getJson("{$this->base_tenant_api_admin}organizations/{$orgId}", $this->getHeaders())
            ->assertStatus(404);

        $this->postJson("{$this->base_tenant_api_admin}organizations/{$orgId}/restore", [], $this->getHeaders())
            ->assertStatus(200);

        $this->getJson("{$this->base_tenant_api_admin}organizations/{$orgId}", $this->getHeaders())
            ->assertStatus(200);
    }

    public function test_organization_force_delete(): void
    {
        $created = $this->postJson(
            "{$this->base_tenant_api_admin}organizations",
            ['name' => 'Org Force'],
            $this->getHeaders()
        );

        $created->assertStatus(201);
        $orgId = $created->json('data._id') ?? $created->json('data.id');

        $this->deleteJson("{$this->base_tenant_api_admin}organizations/{$orgId}", [], $this->getHeaders())
            ->assertStatus(200);

        $this->postJson("{$this->base_tenant_api_admin}organizations/{$orgId}/force_delete", [], $this->getHeaders())
            ->assertStatus(200);

        $this->getJson("{$this->base_tenant_api_admin}organizations/{$orgId}", $this->getHeaders())
            ->assertStatus(404);
    }

    public function test_organization_create_forbidden_without_ability(): void
    {
        $user = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($user, ['account-users:view']);

        $response = $this->postJson("{$this->base_tenant_api_admin}organizations", [
            'name' => 'Forbidden Org',
        ]);

        $response->assertStatus(403);
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
