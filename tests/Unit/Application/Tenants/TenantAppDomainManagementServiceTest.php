<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Tenants\TenantAppDomainManagementService;
use App\Models\Landlord\Tenant;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantAppDomainManagementServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private TenantAppDomainManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->makeCurrent();

        $this->service = $this->app->make(TenantAppDomainManagementService::class);
    }

    public function test_list_returns_tenant_app_domains(): void
    {
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.tenant.theta',
        ]);
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_IOS,
            'path' => 'com.tenant.theta.ios',
        ]);

        $domains = $this->service->list($this->tenant);

        $this->assertSame([
            Tenant::APP_PLATFORM_ANDROID => 'com.tenant.theta',
            Tenant::APP_PLATFORM_IOS => 'com.tenant.theta.ios',
        ], $domains);
    }

    public function test_upsert_persists_unique_domain_per_platform(): void
    {
        $domains = $this->service->upsert(
            $this->tenant,
            Tenant::APP_PLATFORM_ANDROID,
            'com.theta.mobile',
        );

        $this->assertSame('com.theta.mobile', $domains[Tenant::APP_PLATFORM_ANDROID]);
        $this->assertDatabaseHas('domains', [
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'com.theta.mobile',
        ], 'landlord');
    }

    public function test_upsert_rejects_invalid_format_for_platform(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->upsert(
            $this->tenant,
            Tenant::APP_PLATFORM_ANDROID,
            'invalid package',
        );
    }

    public function test_remove_deletes_existing_domain(): void
    {
        $this->tenant->domains()->create([
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'remove-me.test',
        ]);

        $domains = $this->service->remove(
            $this->tenant->fresh(),
            Tenant::APP_PLATFORM_ANDROID,
        );

        $this->assertNull($domains[Tenant::APP_PLATFORM_ANDROID]);
        $this->assertDatabaseMissing('domains', [
            'type' => Tenant::DOMAIN_TYPE_APP_ANDROID,
            'path' => 'remove-me.test',
            'deleted_at' => null,
        ], 'landlord');
    }

    public function test_remove_rejects_missing_domain(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->remove(
            $this->tenant->fresh(),
            Tenant::APP_PLATFORM_ANDROID,
        );
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Theta', 'subdomain' => 'tenant-theta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-theta.test']
        );

        $service->initialize($payload);
    }
}
