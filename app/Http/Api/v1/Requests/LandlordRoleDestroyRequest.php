<?php

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class LandlordRoleDestroyRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'background_role_id' => [
                'required',
                'string',
                'size:'.InputConstraints::OBJECT_ID_LENGTH,
                'regex:/^[a-fA-F0-9]{24}$/',
                'exists:landlord.landlord_roles,_id',
            ],
        ];
    }
}
