<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Rules\EmailAvailableRule;
use App\Support\Validation\CanonicalPasswordRules;
use App\Support\Validation\InputConstraints;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
            'name' => 'required|string|max:'.InputConstraints::NAME_MAX,
            'email' => [
                'required',
                'string',
                'email',
                'max:'.InputConstraints::EMAIL_MAX,
                new EmailAvailableRule('landlord', 'landlord_users'),
            ],
            'password' => CanonicalPasswordRules::required(confirmed: true),
            'device_name' => 'required|string|max:'.InputConstraints::NAME_MAX,
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'errors' => $validator->errors(),
        ], 422));
    }
}
