<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Profiles\TenantProfileService;
use App\Application\Telemetry\TelemetryEmitter;
use App\Http\Api\v1\Requests\EmailsAddRequest;
use App\Http\Api\v1\Requests\EmailRemoveRequest;
use App\Http\Api\v1\Requests\GenerateTokenRequest;
use App\Http\Api\v1\Requests\PhoneRemoveRequest;
use App\Http\Api\v1\Requests\PhonesAddRequest;
use App\Http\Api\v1\Requests\ResetPasswordRequestContract;
use App\Http\Api\v1\Requests\UpdatePasswordRequest;
use App\Http\Api\v1\Requests\UpdateProfileRequestContract;
use App\Http\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;

class ProfileControllerTenant extends Controller
{
    public function __construct(
        private readonly TenantProfileService $profileService,
        private readonly TelemetryEmitter $telemetry
    ) {
    }

    public function updateProfile(UpdateProfileRequestContract $request): JsonResponse
    {
        /** @var AccountUser $user */
        $user = $request->user();

        $validated = $request->validated();
        $updated = $this->profileService->updateProfile($user, $validated);

        $this->telemetry->emit(
            event: 'profile_updated',
            userId: (string) $user->_id,
            properties: [
                'changed_fields' => array_keys($validated),
            ],
            idempotencyKey: $request->header('X-Request-Id')
        );

        return response()->json(UserResource::make($updated));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var AccountUser $user */
        $user = $request->user();

        $this->profileService->updatePassword($user, $request->validated()['password']);

        $this->telemetry->emit(
            event: 'profile_password_updated',
            userId: (string) $user->_id,
            idempotencyKey: $request->header('X-Request-Id')
        );

        return response()->json(['message' => 'Senha atualizada com sucesso']);
    }

    public function generateToken(GenerateTokenRequest $request): JsonResponse
    {
        $email = $request->validated()['email'];
        $this->profileService->sendResetToken($email);

        $user = AccountUser::query()
            ->where('emails', 'all', [strtolower($email)])
            ->first();

        if ($user) {
            $this->telemetry->emit(
                event: 'auth_password_token_generated',
                userId: (string) $user->_id,
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json([
            'message' => "Token gerado e será enviado caso exista uma conta com o email '$email'.",
        ]);
    }

    public function resetPassword(ResetPasswordRequestContract $request): JsonResponse
    {
        $validated = $request->validated();

        $this->profileService->resetPassword(
            $validated['email'],
            $validated['reset_token'],
            $validated['password']
        );

        $user = AccountUser::query()
            ->where('emails', 'all', [strtolower($validated['email'])])
            ->first();

        if ($user) {
            $this->telemetry->emit(
                event: 'auth_password_reset',
                userId: (string) $user->_id,
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

        return response()->json(['message' => 'Senha atualizada com sucesso']);
    }

    public function addEmails(EmailsAddRequest $request): JsonResponse
    {
        /** @var AccountUser $user */
        $user = $request->user();

        $updated = $this->profileService->addEmail($user, $request->validated()['email']);

        $this->telemetry->emit(
            event: 'profile_email_added',
            userId: (string) $user->_id,
            properties: [
                'emails_count' => count($updated->emails ?? []),
            ],
            idempotencyKey: $request->header('X-Request-Id')
        );

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $updated,
        ]);
    }

    public function removeEmail(EmailRemoveRequest $request): JsonResponse
    {
        /** @var AccountUser $user */
        $user = $request->user();

        $updated = $this->profileService->removeEmail($user, $request->validated()['email']);

        $this->telemetry->emit(
            event: 'profile_email_removed',
            userId: (string) $user->_id,
            properties: [
                'emails_count' => count($updated->emails ?? []),
            ],
            idempotencyKey: $request->header('X-Request-Id')
        );

        return response()->json([
            'message' => 'Telefone adicionado com sucesso',
            'data' => $updated,
        ]);
    }

    public function addPhones(PhonesAddRequest $request): JsonResponse
    {
        /** @var AccountUser $user */
        $user = $request->user();

        $updated = $this->profileService->addPhones($user, $request->validated()['phones']);

        $this->telemetry->emit(
            event: 'profile_phone_added',
            userId: (string) $user->_id,
            properties: [
                'phones_count' => count($updated->phones ?? []),
            ],
            idempotencyKey: $request->header('X-Request-Id')
        );

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $updated,
        ]);
    }

    public function removePhone(PhoneRemoveRequest $request): JsonResponse
    {
        /** @var AccountUser $user */
        $user = $request->user();

        $updated = $this->profileService->removePhone($user, $request->validated()['phone']);

        $this->telemetry->emit(
            event: 'profile_phone_removed',
            userId: (string) $user->_id,
            properties: [
                'phones_count' => count($updated->phones ?? []),
            ],
            idempotencyKey: $request->header('X-Request-Id')
        );

        return response()->json([
            'message' => 'Telefone removido com sucesso',
            'data' => $updated,
        ]);
    }
}
