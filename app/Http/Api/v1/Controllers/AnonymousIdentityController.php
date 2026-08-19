<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Identity\AnonymousIdentityService;
use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Tenants\TenantRequestLifecycleTrace;
use App\Http\Api\v1\Requests\AnonymousIdentityRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class AnonymousIdentityController extends Controller
{
    public function __construct(
        private readonly AnonymousIdentityService $identityService,
        private readonly TelemetryEmitter $telemetry
    ) {}

    public function store(AnonymousIdentityRequest $request): JsonResponse
    {
        app(TenantRequestLifecycleTrace::class)->record('endpoint.anonymous_identity.controller.enter');

        $tenant = Tenant::resolve();

        $validated = $request->validated();
        $validated['fingerprint']['user_agent'] ??= $request->userAgent();

        $result = $this->identityService->register($tenant, $validated);

        app(TenantRequestLifecycleTrace::class)->record('endpoint.anonymous_identity.identity_registered', [
            'identity_state' => $result->user->identity_state,
        ]);

        $this->telemetry->emit(
            event: 'anonymous_identity_created',
            userId: (string) $result->user->_id,
            properties: [
                'identity_state' => $result->user->identity_state,
            ],
            idempotencyKey: $request->header('X-Request-Id')
        );

        $response = [
            'data' => [
                'user_id' => (string) $result->user->_id,
                'identity_state' => $result->user->identity_state,
                'token' => $result->plainTextToken,
                'abilities' => $result->abilities,
            ],
        ];

        if ($result->expiresAt) {
            $response['data']['expires_at'] = $result->expiresAt->toISOString();
        }

        app(TenantRequestLifecycleTrace::class)->record('endpoint.anonymous_identity.response_ready');

        return response()->json($response, 201);
    }
}
