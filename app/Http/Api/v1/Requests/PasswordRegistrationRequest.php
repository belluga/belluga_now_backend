<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class PasswordRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.InputConstraints::NAME_MAX],
            'email' => ['required', 'email', 'max:'.InputConstraints::EMAIL_MAX],
            'password' => [
                'required',
                'string',
                'min:'.InputConstraints::PASSWORD_MIN,
                'max:'.InputConstraints::PASSWORD_MAX,
            ],
            'anonymous_user_ids' => [
                'sometimes',
                'array',
                'min:1',
                'max:'.InputConstraints::EMAIL_ARRAY_MAX,
            ],
            'anonymous_user_ids.*' => [
                'required',
                'string',
                'size:'.InputConstraints::OBJECT_ID_LENGTH,
                'regex:/^[a-fA-F0-9]{24}$/',
            ],
        ];
    }
}
