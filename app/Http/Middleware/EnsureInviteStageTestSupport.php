<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Landlord\Tenant;
use Closure;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnsureInviteStageTestSupport
{
    public function handle(mixed $request, Closure $next): mixed
    {
        if (! $this->isEnabled()) {
            throw new NotFoundHttpException;
        }

        $tenant = Tenant::current();
        if (! $tenant instanceof Tenant) {
            throw new NotFoundHttpException;
        }

        $allowedTenants = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('test_support.invites.allowed_tenants', [])
        )));
        if (! in_array((string) $tenant->slug, $allowedTenants, true)) {
            throw new NotFoundHttpException;
        }

        $headerName = trim((string) config('test_support.invites.secret_header', 'X-Test-Support-Key'));
        $expectedSecret = (string) config('test_support.invites.secret', '');
        $providedSecret = trim((string) $request->header($headerName));

        if ($headerName === '' || $expectedSecret === '' || $providedSecret === '' || ! hash_equals($expectedSecret, $providedSecret)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }

    private function isEnabled(): bool
    {
        return (bool) config('test_support.invites.enabled', false)
            && trim((string) config('app.env', 'production')) === 'stage';
    }
}
