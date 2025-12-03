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

    public function testListReturnsTenantAppDomains(): void
    {
        $this->tenant->update(['app_domains' => ['tenant-theta.app']]);

        $domains = $this->service->list($this->tenant);

        $this->assertSame(['tenant-theta.app'], $domains);
    }

    public function testAddPersistsUniqueDomain(): void
    {
        $domains = $this->service->add($this->tenant, 'theta-app.test');

        $this->assertContains('theta-app.test', $domains);
        $this->assertContains('theta-app.test', $this->tenant->fresh()->app_domains);
    }

    public function testAddRejectsDuplicateDomain(): void
    {
        $this->service->add($this->tenant, 'duplicate-app.test');

        $this->expectException(ValidationException::class);
        $this->service->add($this->tenant, 'duplicate-app.test');
    }

    public function testRemoveDeletesExistingDomain(): void
    {
        $this->tenant->update(['app_domains' => ['remove-me.test', 'keep-me.test']]);

        $domains = $this->service->remove($this->tenant->fresh(), 'remove-me.test');

        $this->assertSame(['keep-me.test'], $domains);
        $this->assertSame(['keep-me.test'], $this->tenant->fresh()->app_domains);
    }

    public function testRemoveRejectsMissingDomain(): void
    {
        $this->tenant->update(['app_domains' => ['present.test']]);

        $this->expectException(ValidationException::class);
        $this->service->remove($this->tenant->fresh(), 'absent.test');
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

