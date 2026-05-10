<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HydrateBearerTokenFromQuery
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->bearerToken()) {
            return $next($request);
        }

        $accessToken = trim((string) $request->query('access_token', ''));
        if ($accessToken !== '') {
            $request->headers->set('Authorization', 'Bearer '.$accessToken);
        }

        return $next($request);
    }
}
