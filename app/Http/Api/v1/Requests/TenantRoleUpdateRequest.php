<?php

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TenantRoleUpdateRequest extends FormRequest
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
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.add' => ['sometimes', 'array', 'prohibits:permissions.set'],
            'permissions.remove' => ['sometimes', 'array', 'prohibits:permissions.set'],
            'permissions.set' => ['sometimes', 'array', 'prohibits:permissions.add,permissions.remove'],
            'permissions.add.*' => ['required', 'string', 'regex:/^(?:[a-z]+(?:-[a-z]+)*|\*)(?::(\\*|[a-z]+))?$/'],
            'permissions.remove.*' => ['required', 'string', 'regex:/^(?:[a-z]+(?:-[a-z]+)*|\*)(?::(\\*|[a-z]+))?$/'],
            'permissions.set.*' => ['required', 'string', 'regex:/^(?:[a-z]+(?:-[a-z]+)*|\*)(?::(\\*|[a-z]+))?$/'],
            'is_default' => ['boolean'],
        ];
    }
}
