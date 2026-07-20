<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfilePublicCatalogSnapshotReader;
use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantProfileType;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfilePublicCatalogSnapshotReaderTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

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
            'is_queryable' => true,
            'is_publicly_discoverable' => true,
            'is_favoritable' => true,
            'is_poi_enabled' => true,
            'has_nested_profile_groups' => true,
        ]);
        $this->createType('artist', 'Alpha Artist', [
            'is_queryable' => true,
            'is_publicly_discoverable' => true,
            'is_favoritable' => true,
            'is_poi_enabled' => false,
            'has_nested_profile_groups' => false,
        ]);
        $this->createType('hidden', 'Hidden Type', [
            'is_queryable' => true,
            'is_publicly_discoverable' => true,
            'is_favoritable' => false,
            'is_poi_enabled' => true,
            'has_nested_profile_groups' => true,
        ]);

        $reader = new AccountProfilePublicCatalogSnapshotReader(
            new AccountProfileTypeCapabilityCatalog,
        );

        $snapshot = $reader->catalogSnapshot();

        $this->assertSame(['artist', 'venue'], $snapshot->catalogTypeKeys());
        $this->assertSame(['venue'], $snapshot->nestedParentTypeKeys());
        $this->assertTrue($snapshot->policy()->canOpenPublicDetail(
            new \App\Models\Tenants\AccountProfile([
                'profile_type' => 'venue',
                'is_active' => true,
                'visibility' => 'public',
                'slug' => 'venue-detail',
            ]),
        ));
        $this->assertSame(['artist', 'venue'], array_column($snapshot->filterOptions(), 'value'));
        $this->assertSame(['Alpha Artist', 'Zoo Venue'], array_column($snapshot->filterOptions(), 'label'));
        $this->assertNotSame('', trim((string) ($snapshot->filterOptions()[0]['id'] ?? '')));
        $this->assertSame($snapshot, $reader->catalogSnapshot());
    }

    public function test_it_uses_a_separate_direct_public_poi_key_read_for_near_only_requests(): void
    {
        $this->createType('venue', 'Venue', [
            'is_queryable' => true,
            'is_publicly_discoverable' => true,
            'is_favoritable' => true,
            'is_poi_enabled' => true,
        ]);
        $this->createType('artist', 'Artist', [
            'is_queryable' => true,
            'is_publicly_discoverable' => true,
            'is_favoritable' => true,
            'is_poi_enabled' => false,
        ]);

        $reader = new AccountProfilePublicCatalogSnapshotReader(
            new AccountProfileTypeCapabilityCatalog,
        );

        $this->assertSame(['venue'], $reader->publicPoiTypeKeys());
        $this->assertSame(['venue'], $reader->publicPoiTypeKeys());
        $this->assertSame(['venue'], $reader->publicPoiEligibilityPolicy()->catalogTypeKeys());
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
