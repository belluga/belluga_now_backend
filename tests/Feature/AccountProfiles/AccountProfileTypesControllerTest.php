<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantProfileType;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountProfileTypesControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

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

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
    }

    public function testProfileTypeIndexListsRegistry(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => ['music_genre'],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => false,
            ],
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.type', 'artist');
    }

    public function testProfileTypeCreate(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'venue',
                'label' => 'Venue',
                'allowed_taxonomies' => ['cuisine'],
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_poi_enabled' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'venue');
        $response->assertJsonPath('data.capabilities.is_poi_enabled', true);
    }

    public function testProfileTypeCreateValidation(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testProfileTypeCreateRejectsDuplicateType(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'venue',
                'label' => 'Venue',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testProfileTypeCreateValidatesAllowedTaxonomiesLength(): void
    {
        $longValue = str_repeat('a', 300);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            [
                'type' => 'venue',
                'label' => 'Venue',
                'allowed_taxonomies' => [$longValue],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function testProfileTypeUpdate(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => false,
                'is_poi_enabled' => false,
            ],
        ]);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profile_types/personal",
            [
                'label' => 'Pessoa',
                'capabilities' => [
                    'is_favoritable' => true,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.label', 'Pessoa');
        $response->assertJsonPath('data.capabilities.is_favoritable', true);
    }

    public function testProfileTypeDelete(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => false,
            ],
        ]);

        $this->deleteJson(
            "{$this->base_tenant_api_admin}account_profile_types/artist",
            [],
            $this->getHeaders()
        )->assertStatus(200);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profile_types",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }
}
