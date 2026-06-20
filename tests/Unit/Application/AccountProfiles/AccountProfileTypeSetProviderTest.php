<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantProfileType;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileTypeSetProviderTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        app()->forgetInstance((string) config('multitenancy.current_tenant_container_key'));

        parent::tearDown();
    }

    public function test_remember_scopes_cached_type_sets_by_current_tenant(): void
    {
        $provider = new AccountProfileTypeSetProvider;
        $remember = new ReflectionMethod(AccountProfileTypeSetProvider::class, 'remember');
        $remember->setAccessible(true);

        $tenantOne = new Tenant;
        $tenantOne->_id = 'tenant-one';
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $tenantOne);

        $first = $remember->invoke(
            $provider,
            'publicly_navigable',
            static fn (): array => ['artist']
        );
        $this->assertSame(['artist'], $first);

        $tenantTwo = new Tenant;
        $tenantTwo->_id = 'tenant-two';
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $tenantTwo);

        $second = $remember->invoke(
            $provider,
            'publicly_navigable',
            static fn (): array => ['venue']
        );
        $this->assertSame(['venue'], $second);

        app()->instance((string) config('multitenancy.current_tenant_container_key'), $tenantOne);

        $third = $remember->invoke(
            $provider,
            'publicly_navigable',
            static fn (): array => ['should-not-run']
        );
        $this->assertSame(['artist'], $third);
    }

    public function test_is_publicly_navigable_refreshes_after_profile_type_update_without_recreating_provider(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $provider = new AccountProfileTypeSetProvider;
        $this->assertTrue($provider->isPubliclyNavigable('venue'));

        $venueType = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $venueType->capabilities = [
            'is_queryable' => true,
            'is_publicly_navigable' => false,
            'is_publicly_discoverable' => true,
            'is_poi_enabled' => true,
        ];
        $venueType->save();

        $this->assertFalse($provider->isPubliclyNavigable('venue'));
    }

    public function test_is_publicly_navigable_refreshes_after_profile_type_deletion_without_recreating_provider(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $provider = new AccountProfileTypeSetProvider;
        $this->assertTrue($provider->isPubliclyNavigable('venue'));

        TenantProfileType::query()->where('type', 'venue')->firstOrFail()->delete();

        $this->assertFalse($provider->isPubliclyNavigable('venue'));
    }

    public function test_has_gallery_enabled_refreshes_after_profile_type_update_without_recreating_provider(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'has_gallery' => true,
            ],
        ]);

        $provider = new AccountProfileTypeSetProvider;
        $this->assertTrue($provider->hasGalleryEnabled('venue'));

        $venueType = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $venueType->capabilities = [
            'is_queryable' => true,
            'is_publicly_navigable' => true,
            'is_publicly_discoverable' => true,
            'has_gallery' => false,
        ];
        $venueType->save();

        $this->assertFalse($provider->hasGalleryEnabled('venue'));
    }

    public function test_has_gallery_enabled_refreshes_after_profile_type_deletion_without_recreating_provider(): void
    {
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'has_gallery' => true,
            ],
        ]);

        $provider = new AccountProfileTypeSetProvider;
        $this->assertTrue($provider->hasGalleryEnabled('venue'));

        TenantProfileType::query()->where('type', 'venue')->firstOrFail()->delete();

        $this->assertFalse($provider->hasGalleryEnabled('venue'));
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
