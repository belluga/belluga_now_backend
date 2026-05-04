<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Auth\PhoneOtpCooldownException;
use App\Application\Auth\PhoneOtpVerificationResult;
use App\Application\Auth\TenantPhoneOtpAuthService;
use App\Application\Telemetry\TelemetryEmitter;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Http\Api\v1\Requests\PhoneOtpChallengeRequest;
use App\Http\Api\v1\Requests\PhoneOtpVerifyRequest;
use App\Http\Api\v1\Resources\MeResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class PhoneOtpAuthController extends Controller
{
    public function __construct(
        private readonly TenantPhoneOtpAuthService $authService,
        private readonly TelemetryEmitter $telemetry,
    ) {}

    public function challenge(PhoneOtpChallengeRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->challenge($request->validated());
        } catch (PhoneOtpCooldownException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'retry_after' => $exception->retryAfterSeconds,
            ], 429);
        }

        $this->telemetry->emit(
            event: 'otp_challenge_started',
            userId: null,
            properties: [
                'challenge_id' => $result->challengeId,
                'delivery_channel' => $result->channel,
            ],
            idempotencyKey: $request->header('X-Request-Id'),
            context: [
                'actor' => [
                    'type' => 'phone_otp_challenge',
                    'id' => $result->challengeId,
                ],
                'target' => [
                    'type' => 'phone',
                    'id' => hash('sha256', $result->phone),
                ],
            ],
        );

        return response()->json([
            'data' => [
                'challenge_id' => $result->challengeId,
                'phone' => $result->phone,
                'expires_at' => $result->expiresAt->toISOString(),
                'resend_available_at' => $result->resendAvailableAt->toISOString(),
                'delivery' => [
                    'channel' => $result->channel,
                ],
            ],
        ], 202);
    }

    public function verify(PhoneOtpVerifyRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();

        try {
            $result = $this->authService->verify($tenant, $request->validated());
        } catch (ConcurrencyConflictException) {
            return response()->json([
                'message' => 'A concurrency conflict occurred. Please try again.',
            ], 409);
        }

        $this->emitVerificationTelemetry($result, $request->header('X-Request-Id'));

        return response()->json([
            'data' => [
                'user_id' => (string) $result->user->_id,
                'identity_state' => $result->user->identity_state,
                'token' => $result->plainTextToken,
                'me' => MeResource::fromTenant($result->user),
            ],
        ]);
    }

    private function emitVerificationTelemetry(PhoneOtpVerificationResult $result, ?string $idempotencyKey): void
    {
        $this->telemetry->emit(
            event: 'otp_verified',
            userId: (string) $result->user->_id,
            properties: [
                'identity_state' => $result->user->identity_state,
            ],
            idempotencyKey: $idempotencyKey,
        );

        if ($result->mergedAnonymousUserIds === []) {
            return;
        }

        $this->telemetry->emit(
            event: 'auth_merge_completed',
            userId: (string) $result->user->_id,
            properties: [
                'source_count' => count($result->mergedAnonymousUserIds),
                'source_kind' => 'anonymous',
            ],
            idempotencyKey: $idempotencyKey ? $idempotencyKey.':merge' : null,
        );
    }
}
