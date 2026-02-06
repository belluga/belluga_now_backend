<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Environment\EnvironmentResolverService;
use App\Http\Api\v1\Requests\EnvironmentRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EnvironmentController extends Controller
{
    public function __construct(
        private readonly EnvironmentResolverService $environmentService
    ) {
    }

    public function showEnvironmentData(EnvironmentRequest $request): JsonResponse
    {
        $resolved = $this->environmentService->resolve([
            ...$request->validated(),
            'request_root' => $request->root(),
            'request_host' => $request->getHost(),
        ]);

        $payload = [
            'type' => $resolved['type'] ?? null,
            'tenant_id' => $resolved['tenant_id'] ?? null,
            'name' => $resolved['name'] ?? null,
            'subdomain' => $resolved['subdomain'] ?? null,
            'main_domain' => $resolved['main_domain'] ?? null,
            'domains' => $resolved['domains'] ?? [],
            'app_domains' => $resolved['app_domains'] ?? [],
            'theme_data_settings' => $resolved['theme_data_settings'] ?? [],
            'telemetry' => $resolved['telemetry'] ?? [],
            'firebase' => $resolved['firebase'] ?? [],
            'push' => $resolved['push'] ?? [],
            'profile_types' => $resolved['profile_types'] ?? [],
        ];

        return response()->json($payload);
    }
}
