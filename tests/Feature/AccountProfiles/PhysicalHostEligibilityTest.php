<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\AccountProfiles\PhysicalHostEligibilityPolicy;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class PhysicalHostEligibilityTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

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
            app(SystemInitializationService::class)->initialize(new InitializationPayload(
                landlord: ['name' => 'Landlord HQ'],
                tenant: ['name' => 'Physical Host', 'subdomain' => 'physical-host'],
                role: ['name' => 'Root', 'permissions' => ['*']],
                user: ['name' => 'Root User', 'email' => 'physical-host@example.org', 'password' => 'Secret!234'],
                themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                logoSettings: ['light_logo_uri' => '/logos/light.png'],
                pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
                tenantDomains: ['physical-host.test'],
            ));
            self::$bootstrapped = true;
        }
        Tenant::query()->firstOrFail()->makeCurrent();
        TenantProfileType::query()->delete();
    }

    public function test_host_eligibility_is_independent_from_queryability_navigation_and_map_projection(): void
    {
        $this->type(host: true, policy: 'optional');
        $profile = new AccountProfile([
            'profile_type' => 'place',
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
        ]);

        self::assertTrue(app(PhysicalHostEligibilityPolicy::class)->isEligible($profile));
    }

    public function test_host_eligibility_fails_closed_without_capability_permission_or_valid_point(): void
    {
        $policy = app(PhysicalHostEligibilityPolicy::class);
        $this->type(host: true, policy: 'optional');
        self::assertFalse($policy->isEligible(new AccountProfile([
            'profile_type' => 'place',
            'location' => null,
        ])));

        $this->type(host: false, policy: 'optional');
        self::assertFalse($policy->isEligible(new AccountProfile([
            'profile_type' => 'place',
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
        ])));

        $this->type(host: true, policy: 'disabled');
        self::assertFalse($policy->isEligible(new AccountProfile([
            'profile_type' => 'place',
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
        ])));
    }

    private function type(bool $host, string $policy): void
    {
        $registry = app(AccountProfileCapabilityRegistry::class);
        TenantProfileType::query()->where('type', 'place')->delete();
        TenantProfileType::create([
            'type' => 'place',
            'capabilities' => $registry->completeCreationConfiguration([
                'is_queryable' => ['value' => false, 'parameters' => []],
                'is_publicly_navigable' => ['value' => false, 'parameters' => []],
                'is_map_poi_enabled' => ['value' => false, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => $host, 'parameters' => []],
                'location_policy' => ['value' => $policy, 'parameters' => []],
            ]),
        ]);
    }
}
