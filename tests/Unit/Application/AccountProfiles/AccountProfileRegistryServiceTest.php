<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

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
