<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Auth\TenantPublicAuthMethodResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnsureTenantPublicAuthMethod
{
    public function __construct(
        private readonly TenantPublicAuthMethodResolver $authMethodResolver,
    ) {}

    public function handle(Request $request, Closure $next, string $method): mixed
    {
        $normalizedMethod = strtolower(trim($method));
        $governance = $this->authMethodResolver->currentGovernance();

        if (in_array($normalizedMethod, $governance['effective_methods'], true)) {
            return $next($request);
        }

        return $this->disabledResponse($normalizedMethod);
    }

    private function disabledResponse(string $method): JsonResponse
    {
        $label = match ($method) {
            'password' => 'Password authentication',
            'phone_otp' => 'Phone OTP authentication',
            default => 'This authentication method',
        };

        return response()->json([
            'message' => "{$label} is not enabled for this tenant.",
            'errors' => [
                'auth_method' => ["{$label} is not enabled for this tenant."],
            ],
        ], 422);
    }
}
