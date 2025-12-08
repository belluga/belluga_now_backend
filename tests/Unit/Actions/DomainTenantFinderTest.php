<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\DomainTenantFinder;
use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainTenantFinderTest extends TestCase
{
    public function testDelegatesWebDomainResolutionToResolverService(): void
    {
        $tenant = Tenant::make([
            'name' => 'Mock Tenant',
            'subdomain' => 'mock-tenant',
        ]);

        $this->instance(
            TenantDomainResolverService::class,
            $this->mock(TenantDomainResolverService::class, function (MockInterface $mock) use ($tenant) {
                $mock->shouldReceive('findTenantByDomain')
                    ->once()
                    ->with('tenant.example.test')
                    ->andReturn($tenant);
            })
        );

        /** @var DomainTenantFinder $finder */
        $finder = $this->app->make(DomainTenantFinder::class);

        $request = Request::create('https://tenant.example.test/environment', 'GET');
        $this->app->instance('request', $request);

        $result = $finder->findForRequest($request);

        $this->assertSame($tenant, $result);
    }
}
