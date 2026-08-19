<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Tenants\TenantRequestLifecycleTrace;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\AccountUser;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    public function __construct(AuthFactory $auth, private readonly TenantRequestLifecycleTrace $lifecycleTrace)
    {
        parent::__construct($auth);
    }

    public function handle($request, Closure $next, ...$guards)
    {
        $traceSanctum = in_array('sanctum', $guards, true);

        if ($traceSanctum) {
            $this->lifecycleTrace->record('middleware.auth.start', [
                'guard' => 'sanctum',
            ]);
        }

        return parent::handle($request, function ($request) use ($next, $traceSanctum) {
            if ($traceSanctum) {
                $this->lifecycleTrace->record('middleware.auth.passed', [
                    'guard' => 'sanctum',
                    'principal_kind' => $this->principalKind($request->user('sanctum')),
                ]);
            }

            return $next($request);
        }, ...$guards);
    }

    private function principalKind(mixed $principal): string
    {
        return match (true) {
            $principal instanceof AccountUser => 'tenant_account_user',
            $principal instanceof LandlordUser => 'landlord_user',
            $principal === null => 'anonymous',
            default => 'unknown',
        };
    }
}
