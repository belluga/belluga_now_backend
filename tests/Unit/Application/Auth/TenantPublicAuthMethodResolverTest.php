<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Auth;

use App\Application\Auth\TenantPublicAuthMethodResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('atlas-critical')]
class TenantPublicAuthMethodResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_landlord_and_tenant_settings_into_effective_methods(): void
    {
        $resolver = $this->app->make(TenantPublicAuthMethodResolver::class);

        $resolved = $resolver->resolve(
            [
                'available_methods' => ['password', 'phone_otp'],
                'allow_tenant_customization' => true,
            ],
            [
                'enabled_methods' => ['phone_otp'],
            ]
        );

        $this->assertSame(['password', 'phone_otp'], $resolved['available_methods']);
        $this->assertTrue($resolved['allow_tenant_customization']);
        $this->assertSame(['phone_otp'], $resolved['enabled_methods']);
        $this->assertSame(['phone_otp'], $resolved['effective_methods']);
        $this->assertSame('phone_otp', $resolved['effective_primary_method']);
    }

    #[Test]
    public function it_defaults_to_landlord_methods_when_tenant_has_no_enabled_subset(): void
    {
        $resolver = $this->app->make(TenantPublicAuthMethodResolver::class);

        $resolved = $resolver->resolve(
            [
                'available_methods' => ['password', 'phone_otp'],
                'allow_tenant_customization' => true,
            ],
            []
        );

        $this->assertSame(['password', 'phone_otp'], $resolved['effective_methods']);
        $this->assertSame('password', $resolved['effective_primary_method']);
    }

    #[Test]
    public function it_keeps_landlord_methods_when_customization_is_disabled(): void
    {
        $resolver = $this->app->make(TenantPublicAuthMethodResolver::class);

        $resolved = $resolver->resolve(
            [
                'available_methods' => ['password', 'phone_otp'],
                'allow_tenant_customization' => false,
            ],
            [
                'enabled_methods' => ['phone_otp'],
            ]
        );

        $this->assertSame(['password', 'phone_otp'], $resolved['effective_methods']);
    }
}
