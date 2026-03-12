<?php

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
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
            'name' => ['sometimes', 'string', 'max:'.InputConstraints::NAME_MAX],
            'description' => ['nullable', 'string', 'max:'.InputConstraints::DESCRIPTION_MAX],
            'permissions' => ['required', 'array', 'min:1', 'max:'.InputConstraints::PERMISSIONS_ARRAY_MAX],
            'permissions.add' => ['sometimes', 'array', 'max:'.InputConstraints::PERMISSIONS_ARRAY_MAX, 'prohibits:permissions.set'],
            'permissions.remove' => ['sometimes', 'array', 'max:'.InputConstraints::PERMISSIONS_ARRAY_MAX, 'prohibits:permissions.set'],
            'permissions.set' => ['sometimes', 'array', 'max:'.InputConstraints::PERMISSIONS_ARRAY_MAX, 'prohibits:permissions.add,permissions.remove'],
            'permissions.add.*' => ['required', 'string', 'max:'.InputConstraints::PERMISSION_MAX, 'regex:/^(?:[a-z]+(?:-[a-z]+)*|\*)(?::(\\*|[a-z]+))?$/'],
            'permissions.remove.*' => ['required', 'string', 'max:'.InputConstraints::PERMISSION_MAX, 'regex:/^(?:[a-z]+(?:-[a-z]+)*|\*)(?::(\\*|[a-z]+))?$/'],
            'permissions.set.*' => ['required', 'string', 'max:'.InputConstraints::PERMISSION_MAX, 'regex:/^(?:[a-z]+(?:-[a-z]+)*|\*)(?::(\\*|[a-z]+))?$/'],
            'is_default' => ['boolean'],
        ];
    }
}
