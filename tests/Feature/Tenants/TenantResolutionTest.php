<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
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

    public function testReturns404WhenTenantCannotBeResolved(): void
    {
        $payload = [
            'device_name' => 'unknown-host-device',
            'fingerprint' => [
                'hash' => str_repeat('a', 64),
            ],
        ];

        $response = $this->postJson(
            sprintf('http://%s.%s/api/v1/anonymous/identities', 'unknown', $this->host),
            $payload
        );

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource you are looking for was not found.']);
    }

    public function testTenantAuthRoutesAreNotAvailableOnMainDomain(): void
    {
        $response = $this->postJson(
            sprintf('http://%s/api/v1/auth/login', $this->host),
            [
                'email' => 'nonexistent@example.org',
                'password' => 'Secret!234',
                'device_name' => 'main-host',
            ]
        );

        $response->assertStatus(404);
    }

    public function testLandlordAdminRoutesAreNotAvailableOnTenantDomain(): void
    {
        $response = $this->getJson(
            sprintf('http://%s.%s/admin/api/v1/tenants', 'tenant-alpha', $this->host)
        );

        $response->assertStatus(404);
    }

    public function testUnknownHostCannotResolveTenantWhenAppDomainIsSentOnlyAsQuery(): void
    {
        $response = $this->getJson(
            sprintf('http://%s.%s/api/v1/environment?app_domain=tenant-alpha.test', 'unknown', $this->host)
        );

        $response->assertStatus(404)
            ->assertJson(['message' => 'Resource you are looking for was not found.']);
    }

    public function testMainDomainIgnoresAppDomainQueryWithoutHeader(): void
    {
        $response = $this->getJson(
            sprintf('http://%s/api/v1/environment?app_domain=tenant-alpha.test', $this->host)
        );

        $response->assertStatus(200)
            ->assertJsonPath('type', 'landlord');
    }

    public function testMainDomainCanResolveTenantUsingAppDomainHeader(): void
    {
        $tenant = Tenant::query()->where('subdomain', 'tenant-alpha')->firstOrFail();

        $response = $this->withHeaders([
            'X-App-Domain' => 'tenant-alpha.test',
        ])->getJson(
            sprintf('http://%s/api/v1/environment', $this->host)
        );

        $response->assertStatus(200)
            ->assertJsonPath('type', 'tenant')
            ->assertJsonPath('subdomain', 'tenant-alpha')
            ->assertJsonPath('main_domain', $tenant->getMainDomain());
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

        Tenant::query()
            ->where('subdomain', 'tenant-alpha')
            ->update(['app_domains' => ['tenant-alpha.test']]);
    }
}
