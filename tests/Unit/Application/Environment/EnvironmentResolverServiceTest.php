<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Environment;

use App\Application\Environment\EnvironmentResolverService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\TenantProfileType;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Belluga\Settings\Models\Tenants\TenantSettings;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

#[Group('atlas-critical')]
class EnvironmentResolverServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private EnvironmentResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->service = $this->app->make(EnvironmentResolverService::class);
    }

    public function test_resolve_returns_tenant_environment_when_available(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $result = $this->service->resolve([
            'app_domain' => 'tenant-beta.test',
            'request_root' => 'https://tenant-beta.test',
            'request_host' => 'tenant-beta.test',
        ]);

        $this->assertSame('tenant', $result['type']);
        $this->assertSame($tenant->name, $result['name']);
        $this->assertSame('https://tenant-beta.test', $result['main_domain']);
        $this->assertArrayHasKey('landlord_domain', $result);
        $this->assertSame(5, $result['telemetry']['location_freshness_minutes'] ?? null);
    }

    public function test_resolve_exposes_effective_tenant_public_auth_from_persisted_settings(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $landlord = LandlordSettings::current();
        $originalLandlord = $landlord?->getAttribute('tenant_public_auth');
        if ($landlord === null) {
            $landlord = new LandlordSettings();
            $landlord->setAttribute('_id', 'settings_root');
        }
        $tenantSettings = TenantSettings::current();
        $originalTenant = $tenantSettings?->getAttribute('tenant_public_auth');
        if ($tenantSettings === null) {
            $tenantSettings = new TenantSettings();
            $tenantSettings->setAttribute('_id', 'settings_root');
        }

        $landlord->setAttribute('tenant_public_auth', [
            'available_methods' => ['password', 'phone_otp'],
            'allow_tenant_customization' => true,
        ]);
        $tenantSettings->setAttribute('tenant_public_auth', [
            'enabled_methods' => ['phone_otp'],
        ]);

        $landlord->save();
        $tenantSettings->save();

        try {
            $result = $this->service->resolve([
                'app_domain' => 'tenant-beta.test',
                'request_root' => 'https://tenant-beta.test',
                'request_host' => 'tenant-beta.test',
            ]);

            $this->assertSame(['password', 'phone_otp'], $result['settings']['tenant_public_auth']['available_methods'] ?? []);
            $this->assertSame(['phone_otp'], $result['settings']['tenant_public_auth']['enabled_methods'] ?? []);
            $this->assertSame(['phone_otp'], $result['settings']['tenant_public_auth']['effective_methods'] ?? []);
            $this->assertSame('phone_otp', $result['settings']['tenant_public_auth']['effective_primary_method'] ?? null);
        } finally {
            if ($originalLandlord !== null) {
                $landlord->setAttribute('tenant_public_auth', $originalLandlord);
                $landlord->save();
            } else {
                $landlord->setAttribute('tenant_public_auth', null);
                $landlord->save();
            }

            if ($originalTenant !== null) {
                $tenantSettings->setAttribute('tenant_public_auth', $originalTenant);
                $tenantSettings->save();
            } else {
                $tenantSettings->setAttribute('tenant_public_auth', null);
                $tenantSettings->save();
            }
        }
    }

    public function test_resolve_tenant_on_landlord_host_keeps_canonical_tenant_main_domain(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $result = $this->service->resolve([
            'request_root' => 'https://landlord.test',
            'request_host' => 'landlord.test',
        ]);

        $this->assertSame('tenant', $result['type']);
        $this->assertSame($tenant->getMainDomain(), $result['main_domain']);
    }

    public function test_resolve_on_subdomain_request_preserves_custom_domain_coexistence(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $tenant->domains()->delete();
        $tenant->domains()->create([
            'path' => 'tenant-beta-custom.test',
            'type' => Tenant::DOMAIN_TYPE_WEB,
        ]);

        $subdomainHost = "{$tenant->subdomain}.{$this->rootHost()}";

        $result = $this->service->resolve([
            'request_root' => "https://{$subdomainHost}",
            'request_host' => $subdomainHost,
        ]);

        $this->assertSame('tenant', $result['type']);
        $this->assertSame("https://{$subdomainHost}", $result['main_domain']);
        $this->assertSame(
            ['tenant-beta-custom.test'],
            $this->extractHosts($result['domains'] ?? [])
        );
    }

    public function test_resolve_exposes_profile_type_type_asset_visual_in_environment_registry(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        $type = new TenantProfileType();
        $type->forceFill([
            'type' => 'restaurant',
            'label' => 'Restaurant',
            'labels' => [
                'singular' => 'Restaurant',
                'plural' => 'Restaurants',
            ],
            'allowed_taxonomies' => [],
            'visual' => [
                'mode' => 'image',
                'image_source' => 'type_asset',
            ],
            'poi_visual' => [
                'mode' => 'image',
                'image_source' => 'type_asset',
            ],
            'type_asset_url' => 'https://tenant-beta.test/api/v1/media/account-profile-types/type-1/type_asset?v=123',
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);
        $type->save();

        $result = $this->service->resolve([
            'app_domain' => 'tenant-beta.test',
            'request_root' => 'https://tenant-beta.test',
            'request_host' => 'tenant-beta.test',
        ]);

        $profileTypes = is_array($result['profile_types'] ?? null)
            ? $result['profile_types']
            : [];
        $restaurant = collect($profileTypes)->firstWhere('type', 'restaurant');

        $this->assertIsArray($restaurant);
        $this->assertSame('Restaurant', data_get($restaurant, 'labels.singular'));
        $this->assertSame('Restaurants', data_get($restaurant, 'labels.plural'));
        $this->assertSame('image', data_get($restaurant, 'visual.mode'));
        $this->assertSame('type_asset', data_get($restaurant, 'visual.image_source'));
        $this->assertSame(
            'https://tenant-beta.test/api/v1/media/account-profile-types/type-1/type_asset?v=123',
            data_get($restaurant, 'visual.image_url')
        );
        $this->assertSame('image', data_get($restaurant, 'poi_visual.mode'));
        $this->assertSame('type_asset', data_get($restaurant, 'poi_visual.image_source'));
        $this->assertSame(
            'https://tenant-beta.test/api/v1/media/account-profile-types/type-1/type_asset?v=123',
            data_get($restaurant, 'poi_visual.image_url')
        );
    }

    public function test_resolve_falls_back_to_landlord_environment(): void
    {
        Tenant::forgetCurrent();

        $result = $this->service->resolve(['request_root' => 'http://landlord.test']);

        $this->assertSame('landlord', $result['type']);
        $this->assertSame('http://landlord.test', $result['main_domain']);
        $this->assertSame('http://landlord.test', $result['landlord_domain']);
        $this->assertSame(5, $result['telemetry']['location_freshness_minutes'] ?? null);
        $this->assertArrayHasKey('tenant_public_auth', $result['settings'] ?? []);
    }

    private function initializeSystem(): void
    {
        /** @var SystemInitializationService $service */
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Beta', 'subdomain' => 'tenant-beta', 'app_domains' => ['tenant-beta.test']],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-beta.test']
        );

        $service->initialize($payload);
    }

    private function rootHost(): string
    {
        $configuredUrl = (string) config('app.url');
        $rootHost = parse_url($configuredUrl, PHP_URL_HOST);
        if (is_string($rootHost) && $rootHost !== '') {
            return $rootHost;
        }

        return trim(str_replace(['https://', 'http://'], '', $configuredUrl), '/');
    }

    /**
     * @param  array<int, mixed>  $domains
     * @return array<int, string>
     */
    private function extractHosts(array $domains): array
    {
        return collect($domains)
            ->map(static fn (mixed $domain): ?string => is_string($domain)
                ? parse_url(str_contains($domain, '://') ? $domain : "https://{$domain}", PHP_URL_HOST)
                : null)
            ->filter()
            ->values()
            ->all();
    }
}
