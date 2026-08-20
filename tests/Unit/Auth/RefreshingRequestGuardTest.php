<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Sanctum\RefreshingRequestGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class RefreshingRequestGuardTest extends TestCase
{
    public function test_callback_resolved_principal_is_refreshed_for_each_request_rebind(): void
    {
        $principalA = $this->createMock(Authenticatable::class);
        $principalB = $this->createMock(Authenticatable::class);
        $callbackInvocations = 0;
        $guard = new RefreshingRequestGuard(
            function () use (&$callbackInvocations, $principalA, $principalB): Authenticatable {
                $callbackInvocations++;

                return $callbackInvocations === 1 ? $principalA : $principalB;
            },
            Request::create('/initial'),
        );

        $this->assertSame($guard, $guard->setRequest(Request::create('/first')));
        $this->assertSame($principalA, $guard->user());
        $this->assertSame($guard, $guard->setRequest(Request::create('/second')));
        $this->assertSame($principalB, $guard->user());
        $this->assertSame(2, $callbackInvocations);
    }

    public function test_explicit_principal_survives_request_rebind_without_callback_resolution(): void
    {
        $principal = $this->createMock(Authenticatable::class);
        $callbackInvocations = 0;
        $guard = new RefreshingRequestGuard(
            function () use (&$callbackInvocations): ?Authenticatable {
                $callbackInvocations++;

                return null;
            },
            Request::create('/initial'),
        );

        $this->assertSame($guard, $guard->setUser($principal));
        $this->assertSame($guard, $guard->setRequest(Request::create('/rebound')));
        $this->assertSame($principal, $guard->user());
        $this->assertSame(0, $callbackInvocations);
    }

    public function test_forgetting_explicit_principal_restores_callback_resolution_on_next_request(): void
    {
        $explicitPrincipal = $this->createMock(Authenticatable::class);
        $callbackPrincipal = $this->createMock(Authenticatable::class);
        $callbackInvocations = 0;
        $guard = new RefreshingRequestGuard(
            function () use (&$callbackInvocations, $callbackPrincipal): Authenticatable {
                $callbackInvocations++;

                return $callbackPrincipal;
            },
            Request::create('/initial'),
        );

        $guard->setUser($explicitPrincipal);
        $this->assertSame($guard, $guard->forgetUser());
        $this->assertSame($guard, $guard->setRequest(Request::create('/after-forget')));
        $this->assertSame($callbackPrincipal, $guard->user());
        $this->assertSame(1, $callbackInvocations);
    }
}
