<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountProfilePublicDetailNavigabilityCapabilityDecouplingTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->firstOrFail()->makeCurrent();

        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        $this->createType('personal', 'Personal', [
            'is_queryable' => false,
            'is_publicly_navigable' => false,
            'is_publicly_discoverable' => false,
            'is_favoritable' => false,
            'is_poi_enabled' => false,
            'has_events' => false,
        ]);
        $this->createType('catalog', 'Catalog', [
            'is_queryable' => true,
            'is_publicly_navigable' => true,
            'is_publicly_discoverable' => true,
            'is_favoritable' => true,
            'is_poi_enabled' => false,
            'has_events' => true,
        ]);
        $this->createType('direct-only', 'Direct Only', [
            'is_queryable' => false,
            'is_publicly_navigable' => true,
            'is_publicly_discoverable' => false,
            'is_favoritable' => false,
            'is_poi_enabled' => false,
            'has_events' => false,
        ]);
        $this->createType('route-disabled', 'Route Disabled', [
            'is_queryable' => true,
            'is_publicly_navigable' => false,
            'is_publicly_discoverable' => false,
            'is_favoritable' => false,
            'is_poi_enabled' => false,
            'has_events' => false,
        ]);
    }

    public function test_public_detail_opens_for_publicly_navigable_profile_type_even_when_not_publicly_discoverable(): void
    {
        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'direct-only',
            'display_name' => 'Direct Only Profile',
            'slug' => 'direct-only-profile',
            'visibility' => 'public',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->tenantPublicAuthHeaders())
            ->getJson("{$this->base_api_tenant}account_profiles/direct-only-profile");

        $response->assertOk();
        $response->assertJsonPath('data.display_name', 'Direct Only Profile');
        $response->assertJsonPath('data.slug', 'direct-only-profile');
        $response->assertJsonPath('data.can_open_public_detail', true);
        $response->assertJsonPath('data.public_detail_path', '/parceiro/direct-only-profile');
    }

    public function test_public_discovery_keeps_publicly_navigable_but_not_discoverable_type_out_of_catalog_results(): void
    {
        [$secondaryAccount] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'catalog',
            'display_name' => 'Catalog Profile',
            'slug' => 'catalog-profile',
            'visibility' => 'public',
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondaryAccount->_id,
            'profile_type' => 'direct-only',
            'display_name' => 'Direct Only Profile',
            'slug' => 'direct-only-profile',
            'visibility' => 'public',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->tenantPublicAuthHeaders())
            ->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $filterKeys = $response->json('discovery_filter_facets.filter_keys') ?? [];

        $this->assertSame(['catalog-profile'], $slugs);
        $this->assertSame(['catalog'], $filterKeys);
    }

    public function test_public_detail_fails_closed_for_non_navigable_profile_type(): void
    {
        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'route-disabled',
            'display_name' => 'Route Disabled Profile',
            'slug' => 'route-disabled-profile',
            'visibility' => 'public',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->tenantPublicAuthHeaders())
            ->getJson("{$this->base_api_tenant}account_profiles/route-disabled-profile");

        $response->assertNotFound();
    }

    /**
     * @param  array<string, bool>  $capabilities
     */
    private function createType(string $type, string $label, array $capabilities): void
    {
        TenantProfileType::query()->create([
            'type' => $type,
            'label' => $label,
            'allowed_taxonomies' => [],
            'visual' => ['mode' => 'icon', 'icon' => 'store'],
            'capabilities' => $capabilities,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function tenantPublicAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->issueAnonymousIdentityToken(),
        ];
    }

    private function issueAnonymousIdentityToken(): string
    {
        $response = $this->postJson("{$this->base_api_tenant}anonymous/identities", [
            'device_name' => 'public-detail-navigability-test-device',
            'fingerprint' => [
                'hash' => hash('sha256', 'public-detail-navigability-test-device'),
                'user_agent' => 'AccountProfilePublicDetailNavigabilityCapabilityDecouplingTest/1.0',
                'locale' => 'pt-BR',
            ],
            'metadata' => [
                'source' => 'feature-test',
            ],
        ]);

        $response->assertStatus(201);

        $token = (string) $response->json('data.token');
        $this->assertNotSame('', trim($token));

        return $token;
    }

    private function initializeSystem(): void
    {
        $this->ensureSystemInitialized();
    }
}
