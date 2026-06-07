<?php

declare(strict_types=1);

namespace Tests\Unit\Application\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Models\Landlord\Tenant;
use ReflectionMethod;
use Tests\TestCase;

class AccountProfileTypeSetProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->forgetInstance((string) config('multitenancy.current_tenant_container_key'));

        parent::tearDown();
    }

    public function test_remember_scopes_cached_type_sets_by_current_tenant(): void
    {
        $provider = new AccountProfileTypeSetProvider;
        $remember = new ReflectionMethod(AccountProfileTypeSetProvider::class, 'remember');
        $remember->setAccessible(true);

        $tenantOne = new Tenant;
        $tenantOne->_id = 'tenant-one';
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $tenantOne);

        $first = $remember->invoke(
            $provider,
            'publicly_navigable',
            static fn (): array => ['artist']
        );
        $this->assertSame(['artist'], $first);

        $tenantTwo = new Tenant;
        $tenantTwo->_id = 'tenant-two';
        app()->instance((string) config('multitenancy.current_tenant_container_key'), $tenantTwo);

        $second = $remember->invoke(
            $provider,
            'publicly_navigable',
            static fn (): array => ['venue']
        );
        $this->assertSame(['venue'], $second);

        app()->instance((string) config('multitenancy.current_tenant_container_key'), $tenantOne);

        $third = $remember->invoke(
            $provider,
            'publicly_navigable',
            static fn (): array => ['should-not-run']
        );
        $this->assertSame(['artist'], $third);
    }
}
