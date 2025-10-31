<?php

namespace App\Http\Api\v1\Requests;

use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class TenantRoleStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:' . InputConstraints::NAME_MAX],
            'description' => ['nullable', 'string', 'max:' . InputConstraints::DESCRIPTION_MAX],
            'permissions' => ['required', 'array', 'max:' . InputConstraints::PERMISSIONS_ARRAY_MAX],
            'permissions.*' => [
                'required',
                'string',
                'max:' . InputConstraints::PERMISSION_MAX,
                'regex:/^(?:[a-z]+(?:-[a-z]+)*|\*)(?::(\\*|[a-z]+))?$/',
            ],
        ];
    }
}
