<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

final class DormantLocationPrivacyTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get => $this->landlord->tenant_primary;
    }

    private Account $account;

    private AccountProfile $profile;

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            app(SystemInitializationService::class)->initialize(new InitializationPayload(
                landlord: ['name' => 'Landlord HQ'],
                tenant: ['name' => $this->tenant->name, 'subdomain' => $this->tenant->subdomain],
                role: ['name' => 'Root', 'permissions' => ['*']],
                user: ['name' => 'Root User', 'email' => 'dormant-location@example.org', 'password' => 'Secret!234'],
                themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                logoSettings: ['light_logo_uri' => '/logos/light.png'],
                pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
                tenantDomains: [$this->tenant->subdomain.'.'.$this->host],
            ));
            self::$bootstrapped = true;
        }

        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();
        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();
        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:update',
        ]);

        TenantProfileType::query()->create([
            'type' => 'dormant-place',
            'label' => 'Dormant Place',
            'allowed_taxonomies' => [],
            'capabilities' => app(AccountProfileCapabilityRegistry::class)->completeCreationConfiguration([
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'location_policy' => ['value' => 'disabled', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => false, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
            ]),
            'capability_revision' => 0,
            'host_admission_fence_revision' => 0,
        ]);

        $this->profile = AccountProfile::query()->create([
            'account_id' => (string) $this->account->getKey(),
            'profile_type' => 'dormant-place',
            'display_name' => 'Dormant Location Profile',
            'slug' => 'dormant-location-profile',
            'visibility' => 'public',
            'is_active' => true,
            'aggregate_revision' => 1,
            'location' => ['type' => 'Point', 'coordinates' => [-40.301, -20.315]],
        ])->fresh();
    }

    public function test_disabled_policy_hides_retained_location_from_public_detail_and_catalog_but_discloses_it_to_admin(): void
    {
        $publicHeaders = ['Authorization' => 'Bearer '.$this->issueAnonymousIdentityToken()];

        $this->withHeaders($publicHeaders)
            ->getJson($this->base_api_tenant.'account_profiles/'.$this->profile->slug)
            ->assertOk()
            ->assertJsonMissingPath('data.location');

        $catalog = $this->withHeaders($publicHeaders)
            ->getJson($this->base_api_tenant.'account_profiles')
            ->assertOk();
        self::assertSame($this->profile->slug, $catalog->json('data.0.slug'));
        $catalog->assertJsonMissingPath('data.0.location');

        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);
        $this->getJson($this->base_tenant_api_admin.'account_profiles/'.$this->profile->getKey())
            ->assertOk()
            ->assertJsonPath('data.location.lat', -20.315)
            ->assertJsonPath('data.location.lng', -40.301);
    }

    public function test_admin_can_explicitly_remove_but_not_replace_a_dormant_location(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), [
            'account-users:view',
            'account-users:update',
        ]);

        $this->patchJson($this->base_tenant_api_admin.'account_profiles/'.$this->profile->getKey(), [
            'location' => ['lat' => -20.316, 'lng' => -40.302],
            'aggregate_revision' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['location']);

        $this->patchJson($this->base_tenant_api_admin.'account_profiles/'.$this->profile->getKey(), [
            'location' => null,
            'aggregate_revision' => 1,
        ])->assertOk()->assertJsonPath('data.location', null);

        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();
        self::assertNull(AccountProfile::query()->findOrFail($this->profile->getKey())->location);
    }

    public function test_near_returns_a_short_candidate_page_without_the_dormant_row_and_keeps_its_candidate_continuation(): void
    {
        TenantProfileType::query()->create([
            'type' => 'near-optional',
            'label' => 'Near Optional',
            'allowed_taxonomies' => [],
            'capabilities' => app(AccountProfileCapabilityRegistry::class)->completeCreationConfiguration([
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'location_policy' => ['value' => 'optional', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => false, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
            ]),
            'capability_revision' => 0,
            'host_admission_fence_revision' => 0,
        ]);
        TenantProfileType::query()->create([
            'type' => 'near-required',
            'label' => 'Near Required',
            'allowed_taxonomies' => [],
            'capabilities' => app(AccountProfileCapabilityRegistry::class)->completeCreationConfiguration([
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'location_policy' => ['value' => 'required', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => false, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
            ]),
            'capability_revision' => 0,
            'host_admission_fence_revision' => 0,
        ]);
        [$optionalAccount] = $this->seedAccountWithRole();
        $optional = AccountProfile::query()->create([
            'account_id' => (string) $optionalAccount->getKey(),
            'profile_type' => 'near-optional',
            'display_name' => 'Near Optional Profile',
            'slug' => 'near-optional-profile',
            'visibility' => 'public',
            'is_active' => true,
            'aggregate_revision' => 1,
            'location' => ['type' => 'Point', 'coordinates' => [-40.3011, -20.3151]],
        ]);
        [$requiredAccount] = $this->seedAccountWithRole();
        $required = AccountProfile::query()->create([
            'account_id' => (string) $requiredAccount->getKey(),
            'profile_type' => 'near-required',
            'display_name' => 'Near Required Profile',
            'slug' => 'near-required-profile',
            'visibility' => 'public',
            'is_active' => true,
            'aggregate_revision' => 1,
            'location' => ['type' => 'Point', 'coordinates' => [-40.3012, -20.3152]],
        ]);

        $publicHeaders = ['Authorization' => 'Bearer '.$this->issueAnonymousIdentityToken()];

        $firstPage = $this->withHeaders($publicHeaders)->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.315&origin_lng=-40.301&page=1&page_size=2"
        )->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('page_size', 2)
            ->assertJsonPath('has_more', true);

        self::assertSame([(string) $optional->getKey()], collect($firstPage->json('data'))->pluck('id')->all());
        self::assertNotContains((string) $this->profile->getKey(), collect($firstPage->json('data'))->pluck('id')->all());

        $secondPage = $this->withHeaders($publicHeaders)->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.315&origin_lng=-40.301&page=2&page_size=2"
        )->assertOk()
            ->assertJsonPath('page', 2)
            ->assertJsonPath('has_more', false);

        self::assertSame([(string) $required->getKey()], collect($secondPage->json('data'))->pluck('id')->all());
        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();
        $persistedDormantProfile = AccountProfile::query()->findOrFail($this->profile->getKey());
        self::assertSame('Point', data_get($persistedDormantProfile->location, 'type'));
        self::assertSame(-40.301, (float) data_get($persistedDormantProfile->location, 'coordinates.0'));
        self::assertSame(-20.315, (float) data_get($persistedDormantProfile->location, 'coordinates.1'));
    }

    public function test_near_returns_an_empty_candidate_page_without_dormant_rows_and_keeps_the_next_optional_profile_available(): void
    {
        TenantProfileType::query()->create([
            'type' => 'near-optional',
            'label' => 'Near Optional',
            'allowed_taxonomies' => [],
            'capabilities' => app(AccountProfileCapabilityRegistry::class)->completeCreationConfiguration([
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'location_policy' => ['value' => 'optional', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => false, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
            ]),
            'capability_revision' => 0,
            'host_admission_fence_revision' => 0,
        ]);
        [$secondDormantAccount] = $this->seedAccountWithRole();
        AccountProfile::query()->create([
            'account_id' => (string) $secondDormantAccount->getKey(),
            'profile_type' => 'dormant-place',
            'display_name' => 'Second Dormant Location Profile',
            'slug' => 'second-dormant-location-profile',
            'visibility' => 'public',
            'is_active' => true,
            'aggregate_revision' => 1,
            'location' => ['type' => 'Point', 'coordinates' => [-40.3011, -20.3151]],
        ]);
        [$optionalAccount] = $this->seedAccountWithRole();
        $optional = AccountProfile::query()->create([
            'account_id' => (string) $optionalAccount->getKey(),
            'profile_type' => 'near-optional',
            'display_name' => 'Next Page Optional Profile',
            'slug' => 'next-page-optional-profile',
            'visibility' => 'public',
            'is_active' => true,
            'aggregate_revision' => 1,
            'location' => ['type' => 'Point', 'coordinates' => [-40.3012, -20.3152]],
        ]);

        $publicHeaders = ['Authorization' => 'Bearer '.$this->issueAnonymousIdentityToken()];

        $firstPage = $this->withHeaders($publicHeaders)->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.315&origin_lng=-40.301&page=1&page_size=2"
        )->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('page_size', 2)
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data', []);

        self::assertNotContains((string) $this->profile->getKey(), collect($firstPage->json('data'))->pluck('id')->all());

        $secondPage = $this->withHeaders($publicHeaders)->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.315&origin_lng=-40.301&page=2&page_size=2"
        )->assertOk()
            ->assertJsonPath('page', 2)
            ->assertJsonPath('has_more', false);

        self::assertSame([(string) $optional->getKey()], collect($secondPage->json('data'))->pluck('id')->all());
        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();
        $persistedDormantProfile = AccountProfile::query()->findOrFail($this->profile->getKey());
        self::assertSame('Point', data_get($persistedDormantProfile->location, 'type'));
        self::assertSame(-40.301, (float) data_get($persistedDormantProfile->location, 'coordinates.0'));
        self::assertSame(-20.315, (float) data_get($persistedDormantProfile->location, 'coordinates.1'));
    }

    private function issueAnonymousIdentityToken(): string
    {
        $response = $this->postJson($this->base_api_tenant.'anonymous/identities', [
            'device_name' => 'dormant-location-test-device',
            'fingerprint' => [
                'hash' => hash('sha256', 'dormant-location-test-device'),
                'user_agent' => 'DormantLocationPrivacyTest/1.0',
                'locale' => 'pt-BR',
            ],
            'metadata' => ['source' => 'feature-test'],
        ])->assertCreated();

        return (string) $response->json('data.token');
    }
}
