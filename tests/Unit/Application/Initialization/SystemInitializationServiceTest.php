<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Initialization;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class SystemInitializationServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
    }

    public function testInitializeCreatesAllArtifacts(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant A', 'subdomain' => 'tenant-a'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'secret123'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-a.example.test']
        );

        $result = $service->initialize($payload);

        $this->assertDatabaseCount('landlords', 1, 'landlord');
        $this->assertDatabaseCount('tenants', 1, 'landlord');
        $this->assertNotEmpty($result->token);
        $this->assertSame('Root User', $result->user->name);
        $this->assertTrue($service->isInitialized());
    }
}
