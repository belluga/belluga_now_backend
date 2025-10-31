<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Support\Validation\InputConstraints;

class ResetPasswordRequestTenant extends ResetPasswordRequestContract {
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:' . InputConstraints::EMAIL_MAX,
            'password' => [
                'required',
                'string',
                'min:' . InputConstraints::PASSWORD_MIN,
                'max:' . InputConstraints::PASSWORD_MAX,
                'confirmed',
            ],
            'reset_token' => 'required|string|max:255|exists:landlord.password_reset_tokens,token',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()
        ], 422));
    }
}
