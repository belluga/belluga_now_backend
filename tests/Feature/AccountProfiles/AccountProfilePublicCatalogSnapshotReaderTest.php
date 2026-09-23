<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfilePublicCatalogSnapshotReader;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\TenantProfileType;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfilePublicCatalogSnapshotReaderTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();
        $this->initializeSystem();

        Tenant::query()->firstOrFail()->makeCurrent();
        TenantProfileType::query()->delete();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance((string) config('multitenancy.current_tenant_container_key'));

        parent::tearDown();
    }

    public function test_it_builds_one_catalog_snapshot_with_derived_public_sets_and_php_ordered_filter_options(): void
    {
        $this->createType('venue', 'Zoo Venue', [
            'is_queryable' => ['value' => true, 'parameters' => []],
            'is_publicly_navigable' => ['value' => true, 'parameters' => []],
            'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
            'is_favoritable' => ['value' => true, 'parameters' => []],
            'location_policy' => ['value' => 'required', 'parameters' => []], 'is_map_poi_enabled' => ['value' => true, 'parameters' => []], 'is_physical_host_enabled' => ['value' => true, 'parameters' => []], 'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
            'has_nested_profile_groups' => ['value' => true, 'parameters' => []],
        ]);
        $this->createType('artist', 'Alpha Artist', [
            'is_queryable' => ['value' => true, 'parameters' => []],
            'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
            'is_favoritable' => ['value' => true, 'parameters' => []],
            'location_policy' => ['value' => 'disabled', 'parameters' => []], 'is_map_poi_enabled' => ['value' => false, 'parameters' => []], 'is_physical_host_enabled' => ['value' => false, 'parameters' => []], 'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
            'has_nested_profile_groups' => ['value' => false, 'parameters' => []],
        ]);
        $this->createType('hidden', 'Hidden Type', [
            'is_queryable' => ['value' => true, 'parameters' => []],
            'is_publicly_navigable' => ['value' => true, 'parameters' => []],
            'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
            'is_favoritable' => ['value' => false, 'parameters' => []],
            'location_policy' => ['value' => 'required', 'parameters' => []], 'is_map_poi_enabled' => ['value' => true, 'parameters' => []], 'is_physical_host_enabled' => ['value' => true, 'parameters' => []], 'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
            'has_nested_profile_groups' => ['value' => true, 'parameters' => []],
        ]);

        $reader = app(AccountProfilePublicCatalogSnapshotReader::class);
        $publishedAccount = $this->publishedAccount();

        $snapshot = $reader->catalogSnapshot();

        $this->assertSame(['artist', 'hidden', 'venue'], $snapshot->catalogTypeKeys());
        $this->assertSame(['hidden', 'venue'], $snapshot->nestedParentTypeKeys());
        $this->assertTrue($snapshot->policy()->canOpenPublicDetail(
            new \App\Models\Tenants\AccountProfile([
                'profile_type' => 'venue',
                'is_active' => true,
                'visibility' => 'public',
                'slug' => 'venue-detail',
            ]),
            $publishedAccount,
        ));
        $this->assertTrue($snapshot->policy()->canOpenPublicDetail(
            new \App\Models\Tenants\AccountProfile([
                'profile_type' => 'hidden',
                'is_active' => true,
                'visibility' => 'public',
                'slug' => 'hidden-detail',
            ]),
            $publishedAccount,
        ));
        $this->assertSame(['artist', 'hidden', 'venue'], array_column($snapshot->filterOptions(), 'value'));
        $this->assertSame(['Alpha Artist', 'Hidden Type', 'Zoo Venue'], array_column($snapshot->filterOptions(), 'label'));
        $this->assertNotSame('', trim((string) ($snapshot->filterOptions()[0]['id'] ?? '')));
        $this->assertSame($snapshot, $reader->catalogSnapshot());
    }

    public function test_it_uses_a_separate_direct_public_poi_key_read_for_near_only_requests(): void
    {
        $this->createType('venue', 'Venue', [
            'is_queryable' => ['value' => true, 'parameters' => []],
            'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
            'is_favoritable' => ['value' => true, 'parameters' => []],
            'location_policy' => ['value' => 'required', 'parameters' => []], 'is_map_poi_enabled' => ['value' => true, 'parameters' => []], 'is_physical_host_enabled' => ['value' => true, 'parameters' => []], 'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
        ]);
        $this->createType('artist', 'Artist', [
            'is_queryable' => ['value' => true, 'parameters' => []],
            'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
            'is_favoritable' => ['value' => true, 'parameters' => []],
            'location_policy' => ['value' => 'disabled', 'parameters' => []], 'is_map_poi_enabled' => ['value' => false, 'parameters' => []], 'is_physical_host_enabled' => ['value' => false, 'parameters' => []], 'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
        ]);

        $reader = app(AccountProfilePublicCatalogSnapshotReader::class);

        $this->assertSame(['venue'], $reader->publicPoiTypeKeys());
        $this->assertSame(['venue'], $reader->publicPoiTypeKeys());
        $this->assertSame(['venue'], $reader->publicPoiEligibilityPolicy()->catalogTypeKeys());
    }

    public function test_it_decouples_direct_public_detail_keys_from_catalog_membership(): void
    {
        $this->createType('catalog', 'Catalog Type', [
            'is_queryable' => ['value' => true, 'parameters' => []],
            'is_publicly_navigable' => ['value' => true, 'parameters' => []],
            'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
        ]);
        $this->createType('direct-only', 'Direct Only Type', [
            'is_queryable' => ['value' => false, 'parameters' => []],
            'is_publicly_navigable' => ['value' => true, 'parameters' => []],
            'is_publicly_discoverable' => ['value' => false, 'parameters' => []],
        ]);

        $reader = app(AccountProfilePublicCatalogSnapshotReader::class);
        $publishedAccount = $this->publishedAccount();

        $snapshot = $reader->catalogSnapshot();

        $this->assertSame(['catalog'], $snapshot->catalogTypeKeys());
        $this->assertSame(['catalog'], array_column($snapshot->filterOptions(), 'value'));
        $this->assertTrue($snapshot->policy()->canOpenPublicDetail(
            new \App\Models\Tenants\AccountProfile([
                'profile_type' => 'direct-only',
                'is_active' => true,
                'visibility' => 'public',
                'slug' => 'direct-only-detail',
            ]),
            $publishedAccount,
        ));
    }

    public function test_it_refreshes_cached_catalog_and_public_poi_policies_after_profile_type_update_without_recreating_reader(): void
    {
        $this->createType('venue', 'Venue', [
            'is_queryable' => ['value' => true, 'parameters' => []],
            'is_publicly_navigable' => ['value' => true, 'parameters' => []],
            'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
            'is_favoritable' => ['value' => true, 'parameters' => []],
            'location_policy' => ['value' => 'required', 'parameters' => []], 'is_map_poi_enabled' => ['value' => true, 'parameters' => []], 'is_physical_host_enabled' => ['value' => true, 'parameters' => []], 'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
        ]);

        $reader = app(AccountProfilePublicCatalogSnapshotReader::class);
        $publishedAccount = $this->publishedAccount();
        $profile = new \App\Models\Tenants\AccountProfile([
            'profile_type' => 'venue',
            'is_active' => true,
            'visibility' => 'public',
            'slug' => 'venue-detail',
        ]);

        $this->assertTrue($reader->catalogSnapshot()->policy()->canOpenPublicDetail($profile, $publishedAccount));
        $this->assertTrue($reader->publicPoiEligibilityPolicy()->canOpenPublicDetail($profile, $publishedAccount));

        $venueType = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $venueType->capabilities = [
            'is_queryable' => ['value' => true, 'parameters' => []],
            'is_publicly_navigable' => ['value' => false, 'parameters' => []],
            'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
            'is_favoritable' => ['value' => true, 'parameters' => []],
            'location_policy' => ['value' => 'required', 'parameters' => []], 'is_map_poi_enabled' => ['value' => true, 'parameters' => []], 'is_physical_host_enabled' => ['value' => true, 'parameters' => []], 'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
        ];
        $venueType->save();

        $this->assertFalse($reader->catalogSnapshot()->policy()->canOpenPublicDetail($profile, $publishedAccount));
        $this->assertFalse($reader->publicPoiEligibilityPolicy()->canOpenPublicDetail($profile, $publishedAccount));
    }

    public function test_the_container_scopes_the_reader_to_one_request_lifecycle(): void
    {
        $first = $this->app->make(AccountProfilePublicCatalogSnapshotReader::class);
        $second = $this->app->make(AccountProfilePublicCatalogSnapshotReader::class);

        $this->assertSame($first, $second);

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(AccountProfilePublicCatalogSnapshotReader::class));
    }

    /**
     * @param  array<string, bool>  $capabilities
     */
    private function createType(string $type, string $label, array $capabilities): void
    {
        TenantProfileType::query()->create([
            'type' => $type,
            'label' => $label,
            'allowed_taxonomies' => ['cuisine'],
            'visual' => ['mode' => 'icon', 'icon' => 'store'],
            'capabilities' => $capabilities,
        ]);
    }

    private function publishedAccount(): Account
    {
        return new Account([
            'publication' => ['status' => 'published'],
        ]);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $service->initialize(new InitializationPayload(
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
            tenantDomains: ['tenant-zeta.test'],
        ));
    }
}
