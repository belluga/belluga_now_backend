<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Rules\EmailAvailableRule;
use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class LandlordUserCreateRequest extends FormRequest
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
            'email' => [
                'required',
                'string',
                'email',
                'max:' . InputConstraints::EMAIL_MAX,
                new EmailAvailableRule('landlord', 'landlord_users'),
            ],
            'password' => [
                'required',
                'string',
                'min:' . InputConstraints::PASSWORD_MIN,
                'max:' . InputConstraints::PASSWORD_MAX,
            ],
            'role_id' => [
                'required',
                'string',
                'size:' . InputConstraints::OBJECT_ID_LENGTH,
                'regex:/^[a-fA-F0-9]{24}$/',
                'exists:landlord.landlord_roles,_id',
            ],
        ];
    }
}
