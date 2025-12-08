<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantDomainResolverServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private TenantDomainResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
        $this->service = $this->app->make(TenantDomainResolverService::class);
    }

    public function testFindsTenantViaInlineDomainsRegardlessOfCase(): void
    {
        $tenant = Tenant::create([
            'name' => 'Inline Domain',
            'subdomain' => 'inline-domain',
            'domains' => ['ExampleTenant.COM'],
        ]);

        $resolved = $this->service->findTenantByDomain('exampletenant.com');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $tenant->_id, (string) $resolved->_id);
    }

    public function testFallsBackToDomainsCollectionWhenInlineDomainMissing(): void
    {
        $tenant = Tenant::create([
            'name' => 'Collection Tenant',
            'subdomain' => 'collection-tenant',
            'domains' => [],
        ]);

        $domain = new Domains([
            'path' => 'TenantCollection.COM',
            'type' => 'web',
        ]);
        $domain->tenant()->associate($tenant);
        $domain->save();

        $resolved = $this->service->findTenantByDomain('tenantcollection.com');

        $this->assertNotNull($resolved);
        $this->assertSame((string) $tenant->_id, (string) $resolved->_id);
    }
}
