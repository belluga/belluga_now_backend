<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileRegistryServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private AccountProfileRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
        $this->service = $this->app->make(AccountProfileRegistryService::class);
    }

    public function test_location_dependents_return_effective_false_when_location_is_disabled(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'hotel',
            'label' => 'Hotel',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'location_policy' => ['value' => 'disabled', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => true, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => true, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'location_policy' => ['value' => 'required', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => true, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => true, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
            ],
        ]);

        $this->assertFalse($this->service->isMapPoiEnabled('hotel'));
        $this->assertFalse($this->service->isPhysicalHostEnabled('hotel'));
        $this->assertFalse($this->service->isReferenceLocationEnabled('hotel'));
        $this->assertTrue($this->service->isMapPoiEnabled('venue'));
        $this->assertTrue($this->service->isPhysicalHostEnabled('venue'));
        $this->assertTrue($this->service->isReferenceLocationEnabled('venue'));

        $registry = collect($this->service->registry());
        $hotel = $registry->firstWhere('type', 'hotel');
        $venue = $registry->firstWhere('type', 'venue');

        $this->assertTrue((bool) data_get($hotel, 'capabilities.is_map_poi_enabled.configured.value'));
        $this->assertTrue((bool) data_get($hotel, 'capabilities.is_physical_host_enabled.configured.value'));
        $this->assertTrue((bool) data_get($hotel, 'capabilities.is_reference_location_enabled.configured.value'));
        $this->assertFalse((bool) data_get($hotel, 'capabilities.is_map_poi_enabled.effective.value'));
        $this->assertFalse((bool) data_get($hotel, 'capabilities.is_physical_host_enabled.effective.value'));
        $this->assertFalse((bool) data_get($hotel, 'capabilities.is_reference_location_enabled.effective.value'));
        $this->assertTrue((bool) data_get($venue, 'capabilities.is_map_poi_enabled.effective.value'));
        $this->assertTrue((bool) data_get($venue, 'capabilities.is_physical_host_enabled.effective.value'));
        $this->assertTrue((bool) data_get($venue, 'capabilities.is_reference_location_enabled.effective.value'));
    }

    public function test_ensure_defaults_repairs_gallery_capability_for_canonical_public_types(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_favoritable' => ['value' => true, 'parameters' => []],
                'is_inviteable' => ['value' => false, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'location_policy' => ['value' => 'disabled', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => false, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_favoritable' => ['value' => true, 'parameters' => []],
                'is_inviteable' => ['value' => false, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'location_policy' => ['value' => 'required', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => true, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => true, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
            ],
        ]);

        $this->app->make(AccountProfileRegistrySeeder::class)->ensureDefaults();

        $this->assertFalse($this->service->hasGallery('personal'));
        $this->assertTrue($this->service->hasGallery('artist'));
        $this->assertTrue($this->service->hasGallery('venue'));

        $artist = TenantProfileType::query()->where('type', 'artist')->firstOrFail();
        $venue = TenantProfileType::query()->where('type', 'venue')->firstOrFail();

        $this->assertTrue((bool) data_get($artist->capabilities, 'has_gallery.value', false));
        $this->assertTrue((bool) data_get($venue->capabilities, 'has_gallery.value', false));
    }

    public function test_type_definition_is_memoized_across_capability_helpers_within_one_request(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_favoritable' => ['value' => true, 'parameters' => []],
                'is_inviteable' => ['value' => false, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'location_policy' => ['value' => 'disabled', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => false, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => false, 'parameters' => []],
                'has_events' => ['value' => true, 'parameters' => []],
                'has_gallery' => ['value' => true, 'parameters' => ['max_groups' => 6, 'max_items_per_group' => 12]],
            ],
        ]);

        $connection = DB::connection('tenant');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->assertTrue($this->service->hasGallery('artist'));
        $this->assertTrue($this->service->hasEvents('artist'));
        $this->assertFalse($this->service->isMapPoiEnabled('artist'));

        $queryLog = $connection->getQueryLog();
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        $this->assertCount(
            1,
            $queryLog,
            'Repeated capability helpers must reuse the memoized type definition within the request.'
        );
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
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
