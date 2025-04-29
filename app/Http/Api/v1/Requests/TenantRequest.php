<?php

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'subdomain' => 'sometimes|string|max:63',
            'domains' => 'sometimes|array',
            'domains.*' => 'string|max:255',
            'app_domains' => 'sometimes|array',
            'app_domains.*' => 'string|max:255',
        ];

        // Para atualizações, verifica se o subdomínio já existe para outro tenant
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $tenantId = $this->route('id');

            // Adiciona regra para garantir unicidade do subdomínio
            $rules['subdomain'] = [
                'sometimes',
                'string',
                'max:63',
                Rule::unique('mongodb.landlord.tenants', 'subdomain')->ignore($tenantId, '_id')
            ];
        } else {
            // Para criação, simplesmente valida a unicidade
            $rules['subdomain'] = 'sometimes|string|max:63|unique:mongodb.landlord.tenants,subdomain';
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
            'subdomain.unique' => 'Este subdomínio já está em uso',
            'domains.*.string' => 'O domínio deve ser uma string válida',
            'app_domains.*.string' => 'O domínio de app deve ser uma string válida',
        ];
    }
}
