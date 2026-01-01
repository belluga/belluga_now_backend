<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

#[Group('atlas-critical')]
class TenantResolutionTest extends TestCase
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
    }

    public function testReturnsJson400WhenTenantCannotBeResolved(): void
    {
        $payload = [
            'device_name' => 'unknown-host-device',
            'fingerprint' => [
                'hash' => str_repeat('a', 64),
            ],
        ];

        $response = $this->withHeaders(['Host' => 'unknown.example.test'])
            ->postJson('api/v1/anonymous/identities', $payload);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Tenant not found for this host.']);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Alpha', 'subdomain' => 'tenant-alpha'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-alpha.test']
        );

        $service->initialize($payload);
    }
}
