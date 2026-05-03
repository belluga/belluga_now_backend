<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Profiles\LandlordProfileService;
use App\Http\Api\v1\Requests\EmailRemoveRequest;
use App\Http\Api\v1\Requests\EmailsAddRequest;
use App\Http\Api\v1\Requests\GenerateTokenRequest;
use App\Http\Api\v1\Requests\PhoneRemoveRequest;
use App\Http\Api\v1\Requests\PhonesAddRequest;
use App\Http\Api\v1\Requests\ResetPasswordRequestContract;
use App\Http\Api\v1\Requests\UpdatePasswordRequest;
use App\Http\Api\v1\Requests\UpdateProfileRequestLandlord;
use App\Http\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\JsonResponse;

class ProfileControllerLandlord extends Controller
{
    public function __construct(
        private readonly LandlordProfileService $profileService
    ) {}

    public function updateProfile(UpdateProfileRequestLandlord $request): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = $request->user();

        $updated = $this->profileService->updateProfile($user, $request->validated());

        return response()->json(UserResource::make($updated));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = $request->user();

        $this->profileService->updatePassword($user, $request->validated()['password']);

        return response()->json(['message' => 'Senha atualizada com sucesso']);
    }

    public function generateToken(GenerateTokenRequest $request): JsonResponse
    {
        $email = $request->validated()['email'];
        $this->profileService->sendResetToken($email);

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

        return response()->json(['message' => 'Senha atualizada com sucesso']);
    }

    public function addEmails(EmailsAddRequest $request): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = $request->user();

        $updated = $this->profileService->addEmail($user, $request->validated()['email']);

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $updated,
        ]);
    }

    public function removeEmail(EmailRemoveRequest $request): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = $request->user();

        $updated = $this->profileService->removeEmail($user, $request->validated()['email']);

        return response()->json([
            'message' => 'Telefone adicionado com sucesso',
            'data' => $updated,
        ]);
    }

    public function addPhones(PhonesAddRequest $request): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = $request->user();

        $updated = $this->profileService->addPhones($user, $request->validated()['phones']);

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $updated,
        ]);
    }

    public function removePhone(PhoneRemoveRequest $request): JsonResponse
    {
        /** @var LandlordUser $user */
        $user = $request->user();

        $updated = $this->profileService->removePhone($user, $request->validated()['phone']);

        return response()->json([
            'message' => 'Telefone removido com sucesso',
            'data' => $updated,
        ]);
    }
}
