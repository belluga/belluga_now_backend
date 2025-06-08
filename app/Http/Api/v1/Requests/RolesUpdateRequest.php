<?php

namespace App\Http\Api\v1\Requests;

use App\Rules\UniqueArrayItemRule;
use Illuminate\Foundation\Http\FormRequest;

class RolesUpdateRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['sometimes', 'array', new UniqueArrayItemRule("tenant", "roles", "permissions")],
            'permissions.*' => ['required', 'string', 'regex:/^[a-z0-9_\.\*]+$/'],
            'is_default' => ['boolean'],
        ];
    }
}
