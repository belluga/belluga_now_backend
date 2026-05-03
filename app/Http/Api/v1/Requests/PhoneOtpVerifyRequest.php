<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class PhoneOtpVerifyRequest extends FormRequest
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
            'challenge_id' => [
                'required',
                'string',
                'size:'.InputConstraints::OBJECT_ID_LENGTH,
                'regex:/^[a-fA-F0-9]{24}$/',
            ],
            'phone' => ['required', 'string', 'min:8', 'max:32'],
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]{6}$/'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:'.InputConstraints::NAME_MAX],
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
