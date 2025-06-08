<?php

namespace App\Http\Api\v1\Requests;

use App\Rules\UniqueArrayItemRule;
use Illuminate\Foundation\Http\FormRequest;

class RolesDeleteRequest extends FormRequest
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
            'role_id' => ['required', 'string', 'max:255'],
        ];
    }
}
