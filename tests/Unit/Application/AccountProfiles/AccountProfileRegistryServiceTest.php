<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileRegistrySeeder;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantProfileType;
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

    public function test_is_reference_location_enabled_returns_effective_false_when_poi_is_disabled(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'hotel',
            'label' => 'Hotel',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => false,
                'is_reference_location_enabled' => true,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_poi_enabled' => true,
                'is_reference_location_enabled' => true,
            ],
        ]);

        $this->assertFalse($this->service->isReferenceLocationEnabled('hotel'));
        $this->assertTrue($this->service->isReferenceLocationEnabled('venue'));

        $registry = collect($this->service->registry());
        $hotel = $registry->firstWhere('type', 'hotel');
        $venue = $registry->firstWhere('type', 'venue');

        $this->assertFalse((bool) data_get($hotel, 'capabilities.is_reference_location_enabled'));
        $this->assertTrue((bool) data_get($venue, 'capabilities.is_reference_location_enabled'));
    }

    public function test_ensure_defaults_repairs_gallery_capability_for_canonical_public_types(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_inviteable' => false,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => false,
                'has_content' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_inviteable' => false,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
                'has_content' => false,
            ],
        ]);

        $this->app->make(AccountProfileRegistrySeeder::class)->ensureDefaults();

        $this->assertFalse($this->service->hasGallery('personal'));
        $this->assertTrue($this->service->hasGallery('artist'));
        $this->assertTrue($this->service->hasGallery('venue'));

        $artist = TenantProfileType::query()->where('type', 'artist')->firstOrFail();
        $venue = TenantProfileType::query()->where('type', 'venue')->firstOrFail();

        $this->assertTrue((bool) data_get($artist->capabilities, 'has_gallery', false));
        $this->assertTrue((bool) data_get($venue->capabilities, 'has_gallery', false));
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
