<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;
use App\Support\Validation\InputConstraints;

class RegisterUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:' . InputConstraints::NAME_MAX,
            'emails' => 'required|array|max:' . InputConstraints::EMAIL_ARRAY_MAX,
            'emails.*' => 'required|string|email|max:' . InputConstraints::EMAIL_MAX . '|unique:landlord_users',
            'password' => [
                'required',
                'confirmed',
                Password::defaults()->max(InputConstraints::PASSWORD_MAX),
            ],
            'device_name' => 'required|string|max:' . InputConstraints::NAME_MAX,
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors()
        ], 422));
    }
}
