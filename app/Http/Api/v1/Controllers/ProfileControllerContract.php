<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\EmailsAddRequest;
use App\Http\Api\v1\Requests\EmailRemoveRequest;
use App\Http\Api\v1\Requests\GenerateTokenRequest;
use App\Http\Api\v1\Requests\PhoneRemoveRequest;
use App\Http\Api\v1\Requests\PhonesAddRequest;
use App\Http\Api\v1\Requests\ResetPasswordRequest;
use App\Http\Api\v1\Requests\UpdatePasswordRequest;
use App\Http\Api\v1\Requests\UpdateProfileRequestContract;
use App\Http\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

abstract class ProfileControllerContract extends Controller
{

    abstract protected $userModel {
        get;
        set;
    }

    public function updateProfile(UpdateProfileRequestContract $request): JsonResponse {

        if(empty($request->validated())){
            throw ValidationException::withMessages([
                'empty' => "Nenhum dado recebido para atualizar."
            ]);
        }

        $user = auth()->guard('sanctum')->user();

        $user->update($request->validated());

        return response()->json(UserResource::make($user));
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse {

        $user = auth()->guard('sanctum')->user();

        $password = $request->validated()['password'];

        $user->password = Hash::make($password);
        $user->password_type = "laravel";
        $user->save();

        return response()->json(['message' => 'Senha atualizada com sucesso']);
    }

    public function generateToken(GenerateTokenRequest $request): JsonResponse {

        $user_email = $request->validated()['email'];
        $user = $this->userModel::where('emails', $user_email)->first();

        $token = (string) fake()->randomNumber(6);

        if($user){
            $token = DB::table('password_reset_tokens')
                ->insert([
                    'user_id' => $user->id,
                    'token' => $token,
                ]);
            //TODO: Add job to send the token to the email received IF USER EXISTS.
        }

        return response()->json([
            'message' => "Token gerado e será enviado caso exista uma conta com o email '$user_email'."
        ]);
    }

    /**
     * Reseta a senha de um usuário do landlord
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user_email = $request->validated()['email'];
        $reset_token = $request->validated()['reset_token'];
        $password = $request->validated()['password'];

        $user = $this->userModel::where('emails', $user_email)->first();

        if($user){
            $token = DB::table('password_reset_tokens')
                ->where('token', $reset_token)
                ->where('user_id', $user->id)
                ->first();

            if($token){
                $user->password = Hash::make($password);
                $user->password_type = "laravel";
                $user->save();

                return response()->json(['message' => 'Senha atualizada com sucesso']);
            }
        }

        throw ValidationException::withMessages([
            'reset_token' => 'Invalid token',
        ]);
    }

    public function addEmails(EmailsAddRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $new_emails = $request->input('emails');

        try{
            $user->push('emails', $new_emails);
        }catch (\Exception $e){
            if (str_contains($e->getMessage(), 'E11000')) {
                return response()->json([
                    'message' => 'An email already exists.',
                    'errors' => ['emails' => ["One of the emails given already exists.."]]
                ], 422);
            }

            return response()->json([
                "message" => "Erro ao adicionar emails. Tente novamente mais tarde.",
                'errors' => [
                    'emails' => [
                        "Erro ao adicionar emails. Tente novamente mais tarde"
                    ]
                ]
            ],
                422
            );
        }

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $user
        ]);
    }

    public function removeEmail(EmailRemoveRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $remove_email = $request->input('email');

        if(count($user->emails) <= 1) {
            throw ValidationException::withMessages([
                'email' => ['Você não pode remover o único email da conta. Adicione outro email antes de remover esse.'],
            ]);
        }

        try{
            $user->pull('emails', $remove_email);
        }catch (\Exception $e){
            return response()->json([
                "message" => "Erro ao adicionar emails. Tente novamente mais tarde.",
                "errors" => [
                    "emails" => [
                        "Erro ao adicionar emails. Tente novamente mais tarde."
                    ]
                ]
            ],
                422
            );
        }

        return response()->json([
            'message' => 'Telefone adicionado com sucesso',
            'data' => $user
        ]);
    }

    public function addPhones(PhonesAddRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $new_phones = $request->input('phones');

        try{
            $user->push('phones', $new_phones);
        }catch (\Exception $e){
            if (str_contains($e->getMessage(), 'E11000')) {
                return response()->json([
                    'message' => 'One of the provided phones already exists.',
                    'errors' => ['phones' => ["One of the provided phones already exists"]]
                ], 422);
            }

            return response()->json([
                "message" => "Erro ao adicionar telefones. Tente novamente mais tarde.",
                'errors' => [
                    'emails' => [
                        "Erro ao adicionar telefones. Tente novamente mais tarde"
                    ]
                ]
            ],
                422
            );
        }

        return response()->json([
            'message' => 'Usuário atualizado com sucesso',
            'data' => $user
        ]);
    }

    public function removePhone(PhoneRemoveRequest $request): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $remove_phone = $request->input('phone');

        try{
            $user->pull('phones', $remove_phone);
        }catch (\Exception $e){
            return response()->json([
                "message" => "Erro ao adicionar telefone. Tente novamente mais tarde.",
                "errors" => [
                    "emails" => [
                        "Erro ao adicionar telefone. Tente novamente mais tarde."
                    ]
                ]
            ],
                422
            );
        }

        return response()->json([
            'message' => 'Telefone removido com sucesso',
            'data' => $user
        ]);
    }
}
