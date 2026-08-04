<?php

namespace App\Http\Middleware;

use App\Models\Landlord\LandlordUser;
use Closure;
use Illuminate\Auth\AuthenticationException;

class LandlordValidation
{
    public function handle($request, Closure $next)
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user instanceof LandlordUser) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        return $next($request);
    }
}
