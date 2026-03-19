<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Rules\UniqueSubdomainRule;
use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class TenantStoreRequest extends FormRequest
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
        $rules = [
            'name' => 'required|string|max:'.InputConstraints::NAME_MAX,
        ];

        if ($this->isUpdateRequest()) {
            $current_tenant_slug = $this->route('tenant_slug');

            $rules['subdomain'] = [
                'sometimes',
                'string',
                'regex:/^[a-z][a-z0-9-]*[a-z0-9]$/',
                'max:63',
                new UniqueSubdomainRule($current_tenant_slug),

            ];
        } else {
            $rules['subdomain'] = [
                'required',
                'string',
                'regex:/^[a-z][a-z0-9-]*[a-z0-9]$/',
                'max:63',
                new UniqueSubdomainRule,
            ];
        }

        return $rules;
    }

    protected function isUpdateRequest(): bool
    {
        return $this->isMethod('PUT') || $this->isMethod('PATCH');
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'O nome do tenant é obrigatório',
        ];
    }
}
