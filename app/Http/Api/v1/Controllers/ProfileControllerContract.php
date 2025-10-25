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
use App\Support\Helpers\PhoneNumberParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Propaganistas\LaravelPhone\PhoneNumber;

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
        $email = strtolower($request->validated()['email']);

        if (in_array($email, $user->emails ?? [], true)) {
            throw ValidationException::withMessages([
                'email' => ['This email is already associated with your profile.'],
            ]);
        }

        try{
            $user->push('emails', $email);
        }catch (\Exception $e){
            if (str_contains($e->getMessage(), 'E11000')) {
                return response()->json([
                    'message' => 'An email already exists.',
                    'errors' => ['email' => ["The provided email already exists."]]
                ], 422);
            }

            return response()->json([
                "message" => "Erro ao adicionar emails. Tente novamente mais tarde.",
                'errors' => [
                    'email' => [
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
        $phones_raw = $request->input('phones');

        try{
            $phone_numbers = $this->validatePhoneNumbers($phones_raw);

            if(empty($phone_numbers)){
                throw ValidationException::withMessages([
                   "phones" => "None of the provided phones are valid. Please provide a valid phone number."
                ]);
            }

            $user->push('phones', $phone_numbers);

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
            $remove_phone_parsed = PhoneNumberParser::parse($remove_phone);

            if(!$remove_phone_parsed){
                throw ValidationException::withMessages([
                    'phone' => 'The provided phone number is invalid. Please provide a valid phone number.'
                ]);
            }

            $user->pull('phones', $remove_phone_parsed);
        }catch (\Exception $e){
            return response()->json([
                "message" => "Erro ao remover telefone. Tente novamente mais tarde.",
                "errors" => [
                    "emails" => [
                        "Erro ao remover telefone. Tente novamente mais tarde."
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

    protected function validatePhoneNumbers(array $phones_raw): array
    {
        $validated_phone_numbers = [];
        foreach ($phones_raw as $phone) {
            $parsed_phpne = PhoneNumberParser::parse($phone);
            if($parsed_phpne){
                $validated_phone_numbers[] = $parsed_phpne;
            }
        }

        return $validated_phone_numbers;
    }
}
