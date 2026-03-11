<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Identity\TenantPasswordRegistrationResult;
use App\Application\Identity\TenantPasswordRegistrationService;
use App\Application\Telemetry\TelemetryEmitter;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Exceptions\Identity\IdentityAlreadyExistsException;
use App\Http\Api\v1\Requests\PasswordRegistrationRequest;
use App\Http\Api\v1\Resources\MeResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class PasswordRegistrationController extends Controller
{
    public function __construct(
        private readonly TenantPasswordRegistrationService $registrationService,
        private readonly TelemetryEmitter $telemetry
    ) {}

    public function __invoke(
        PasswordRegistrationRequest $request,
    ): JsonResponse {
        $validated = $request->validated();
        $tenant = Tenant::resolve();

        try {
            $result = $this->registrationService->register($tenant, [
                ...$validated,
                'anonymous_user_ids' => $validated['anonymous_user_ids'] ?? [],
            ]);
        } catch (IdentityAlreadyExistsException $exception) {
            return response()->json([
                'message' => 'An identity with this email already exists.',
                'errors' => [
                    'email' => ['This email is already registered for the tenant.'],
                ],
            ], 422);
        } catch (ConcurrencyConflictException) {
            return response()->json([
                'message' => 'A concurrency conflict occurred. Please try again.',
            ], 409);
        }

        return $this->respondWithRegistrationResult($result, $request->header('X-Request-Id'));
    }

    private function respondWithRegistrationResult(
        TenantPasswordRegistrationResult $result,
        ?string $idempotencyKey
    ): JsonResponse {
        $this->telemetry->emit(
            event: 'auth_password_registered',
            userId: (string) $result->user->_id,
            properties: [
                'identity_state' => $result->user->identity_state,
            ],
            idempotencyKey: $idempotencyKey
        );

        $payload = [
            'data' => [
                'user_id' => (string) $result->user->_id,
                'identity_state' => $result->user->identity_state,
                'token' => $result->plainTextToken,
                'me' => MeResource::fromTenant($result->user),
            ],
        ];

        if ($result->expiresAt !== null) {
            $payload['data']['expires_at'] = $result->expiresAt->toISOString();
        }

        return response()->json($payload, 201);
    }
}
