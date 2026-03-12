<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Rules\UniqueSubdomainRule;
use App\Support\Validation\InputConstraints;
use Illuminate\Foundation\Http\FormRequest;

class TenantUpdateRequest extends FormRequest
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

        // Para atualizações, verifica se o subdomínio já existe para outro tenant
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $tenant_slug = $this->route('tenant_slug');

            // Adiciona regra para garantir unicidade do subdomínio
            $rules['subdomain'] = [
                'sometimes',
                'string',
                'regex:/^[a-z][a-z0-9-]*[a-z0-9]$/',
                'max:63',
                new UniqueSubdomainRule($tenant_slug),

            ];
        } else {
            // Para criação, simplesmente valida a unicidade
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

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'O nome do tenant é obrigatório',
            'domains.*.string' => 'O domínio deve ser uma string válida',
            'app_domains.*.string' => 'O domínio de app deve ser uma string válida',
        ];
    }
}
