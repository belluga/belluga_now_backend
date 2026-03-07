<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Environment;

use App\Application\Environment\EnvironmentResolverService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
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

    public function testResolveReturnsTenantEnvironmentWhenAvailable(): void
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

    public function testResolveTenantOnLandlordHostKeepsCanonicalTenantMainDomain(): void
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

    public function testResolveFallsBackToLandlordEnvironment(): void
    {
        Tenant::forgetCurrent();

        $result = $this->service->resolve(['request_root' => 'http://landlord.test']);

        $this->assertSame('landlord', $result['type']);
        $this->assertSame('http://landlord.test', $result['main_domain']);
        $this->assertSame('http://landlord.test', $result['landlord_domain']);
        $this->assertSame(5, $result['telemetry']['location_freshness_minutes'] ?? null);
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
}
