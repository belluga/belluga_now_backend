<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use App\Actions\DomainTenantFinder;
use App\Application\Tenants\TenantAppDomainResolverService;
use App\Application\Tenants\TenantDomainResolverService;
use App\Models\Landlord\Tenant;
use Illuminate\Http\Request;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainTenantFinderTest extends TestCase
{
    public function test_delegates_web_domain_resolution_to_resolver_service(): void
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

    public function test_delegates_app_domain_resolution_on_landlord_host(): void
    {
        $tenant = Tenant::make([
            'name' => 'Mobile Tenant',
            'subdomain' => 'mobile-tenant',
        ]);

        $this->instance(
            TenantAppDomainResolverService::class,
            $this->mock(TenantAppDomainResolverService::class, function (MockInterface $mock) use ($tenant) {
                $mock->shouldReceive('findTenantByIdentifier')
                    ->once()
                    ->with('com.guarappari.app')
                    ->andReturn($tenant);
            })
        );

        $this->instance(
            TenantDomainResolverService::class,
            $this->mock(TenantDomainResolverService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('findTenantByDomain')->never();
            })
        );

        /** @var DomainTenantFinder $finder */
        $finder = $this->app->make(DomainTenantFinder::class);

        $landlordHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($landlordHost) || trim($landlordHost) === '') {
            $landlordHost = trim(str_replace(['https://', 'http://'], '', (string) config('app.url')), '/');
        }

        $request = Request::create("https://{$landlordHost}/api/v1/environment", 'GET');
        $request->headers->set('X-App-Domain', 'com.guarappari.app');
        $this->app->instance('request', $request);

        $result = $finder->findForRequest($request);

        $this->assertSame($tenant, $result);
    }
}
