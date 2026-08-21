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
        $observedPaths = [];
        $guard = new RefreshingRequestGuard(
            function (Request $request) use (&$callbackInvocations, &$observedPaths, $principalA, $principalB): Authenticatable {
                $callbackInvocations++;
                $observedPaths[] = $request->getPathInfo();

                return $callbackInvocations === 1 ? $principalA : $principalB;
            },
            Request::create('/initial'),
        );

        $this->assertSame($guard, $guard->setRequest(Request::create('/first')));
        $this->assertSame($principalA, $guard->user());
        $this->assertSame($guard, $guard->setRequest(Request::create('/second')));
        $this->assertSame($principalB, $guard->user());
        $this->assertSame(2, $callbackInvocations);
        $this->assertSame(['/first', '/second'], $observedPaths);
    }

    public function test_explicit_principal_survives_request_rebind_without_callback_resolution(): void
    {
        $callbackPrincipal = $this->createMock(Authenticatable::class);
        $explicitPrincipal = $this->createMock(Authenticatable::class);
        $replacementPrincipal = $this->createMock(Authenticatable::class);
        $callbackInvocations = 0;
        $observedPaths = [];
        $guard = new RefreshingRequestGuard(
            function (Request $request) use (&$callbackInvocations, &$observedPaths, $callbackPrincipal): Authenticatable {
                $callbackInvocations++;
                $observedPaths[] = $request->getPathInfo();

                return $callbackPrincipal;
            },
            Request::create('/initial'),
        );

        $this->assertSame($guard, $guard->setRequest(Request::create('/callback')));
        $this->assertSame($callbackPrincipal, $guard->user());
        $this->assertSame(1, $callbackInvocations);

        $this->assertSame($guard, $guard->setUser($explicitPrincipal));
        $this->assertSame($guard, $guard->setRequest(Request::create('/explicit')));
        $this->assertSame($explicitPrincipal, $guard->user());
        $this->assertSame(1, $callbackInvocations);

        $this->assertSame($guard, $guard->setUser($replacementPrincipal));
        $this->assertSame($guard, $guard->setRequest(Request::create('/replacement')));
        $this->assertSame($replacementPrincipal, $guard->user());
        $this->assertSame(1, $callbackInvocations);
        $this->assertSame(['/callback'], $observedPaths);
    }

    public function test_forgetting_explicit_principal_restores_callback_resolution_on_next_request(): void
    {
        $explicitPrincipal = $this->createMock(Authenticatable::class);
        $callbackPrincipalA = $this->createMock(Authenticatable::class);
        $callbackPrincipalB = $this->createMock(Authenticatable::class);
        $callbackInvocations = 0;
        $guard = new RefreshingRequestGuard(
            function () use (&$callbackInvocations, $callbackPrincipalA, $callbackPrincipalB): Authenticatable {
                $callbackInvocations++;

                return $callbackInvocations === 1 ? $callbackPrincipalA : $callbackPrincipalB;
            },
            Request::create('/initial'),
        );

        $guard->setUser($explicitPrincipal);
        $this->assertSame($guard, $guard->forgetUser());
        $this->assertSame($guard, $guard->setRequest(Request::create('/after-forget-first')));
        $this->assertSame($callbackPrincipalA, $guard->user());
        $this->assertSame($guard, $guard->setRequest(Request::create('/after-forget-second')));
        $this->assertSame($callbackPrincipalB, $guard->user());
        $this->assertSame(2, $callbackInvocations);
    }
}
