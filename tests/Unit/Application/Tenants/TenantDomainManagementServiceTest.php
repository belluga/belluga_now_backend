<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Tenants\TenantDomainManagementService;
use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantDomainManagementServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private TenantDomainManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->update([
            'app_domains' => ['tenantkappa.app'],
        ]);
        $this->tenant->domains()->updateOrCreate(
            ['path' => 'tenantkappa.test'],
            ['type' => 'web']
        );
        $this->tenant = $this->tenant->fresh();

        $this->service = $this->app->make(TenantDomainManagementService::class);
    }

    public function testCreatePersistsDomainAndUpdatesTenant(): void
    {
        $domain = $this->service->create($this->tenant, ['path' => 'tenantkappa.com']);

        $this->assertInstanceOf(Domains::class, $domain);
        $this->assertContains('tenantkappa.com', $this->tenantDomains());
        $this->assertDatabaseHas('domains', [
            'path' => 'tenantkappa.com',
        ], 'landlord');
    }

    public function testCreateRejectsDuplicateOnSameTenant(): void
    {
        $this->service->create($this->tenant, ['path' => 'duplicatekappa.com']);

        $this->expectException(ValidationException::class);
        $this->service->create($this->tenant->fresh(), ['path' => 'duplicatekappa.com']);
    }

    public function testCreateRejectsDomainUsedByAnotherTenant(): void
    {
        $this->service->create($this->tenant, ['path' => 'sharedkappa.com']);

        $other = Tenant::create([
            'name' => 'Tenant Lambda',
            'subdomain' => 'tenant-lambda',
            'app_domains' => ['tenantlambda.app'],
            'domains' => [],
        ]);
        $other->domains()->delete();
        $other = $other->fresh();

        $this->expectException(ValidationException::class);
        $this->service->create($other, ['path' => 'sharedkappa.com']);
    }

    public function testDeleteSoftDeletesDomainAndUpdatesTenant(): void
    {
        $domain = $this->service->create($this->tenant, ['path' => 'removekappa.com']);

        $this->service->delete($this->tenant, (string) $domain->_id);

        $this->assertNotContains('removekappa.com', $this->tenantDomains());
        $this->assertSoftDeleted('domains', [
            '_id' => $domain->_id,
        ], 'landlord');
    }

    public function testRestoreReaddsDomain(): void
    {
        $domain = $this->service->create($this->tenant, ['path' => 'restorekappa.com']);
        $this->service->delete($this->tenant, (string) $domain->_id);

        $restored = $this->service->restore($this->tenant, (string) $domain->_id);

        $this->assertContains('restorekappa.com', $this->tenantDomains());
        $this->assertFalse($restored->trashed());
    }

    public function testForceDeleteRemovesDomain(): void
    {
        $domain = $this->service->create($this->tenant, ['path' => 'forcekappa.com']);
        $this->service->delete($this->tenant, (string) $domain->_id);

        $this->service->forceDelete($this->tenant, (string) $domain->_id);

        $this->assertDatabaseMissing('domains', [
            '_id' => $domain->_id,
        ], 'landlord');
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Kappa', 'subdomain' => 'tenant-kappa'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenantkappa.test']
        );

        $service->initialize($payload);
    }

    /**
     * @return array<int, string>
     */
    private function tenantDomains(): array
    {
        return $this->tenant
            ->fresh()
            ->domains()
            ->get()
            ->pluck('path')
            ->all();
    }
}
