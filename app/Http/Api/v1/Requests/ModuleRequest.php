<?php

namespace App\Http\Api\v1\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModuleRequest extends FormRequest
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
            'description' => 'sometimes|string',
            'settings' => 'sometimes|array',
            'fields_schema' => 'sometimes|array',
            'permissions_schema' => 'sometimes|array',
            'show_in_menu' => 'sometimes|boolean',
            'menu_position' => 'sometimes|integer',
            'menu_icon' => 'sometimes|string'
        ];

        // Para atualizações, verifica se o nome já existe para outro módulo na mesma conexão tenant
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $moduleId = $this->route('module');

            // Adiciona regra para garantir unicidade do nome no tenant atual
            $rules['name'] = [
                'required',
                'string',
                'max:255',
                Rule::unique('mongodb.tenants.modules', 'name')->ignore($moduleId, '_id')
            ];
        } else {
            // Para criação, simplesmente valida a unicidade
            $rules['name'] = 'required|string|max:255|unique:mongodb.tenants.modules,name';
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
            'name.required' => 'O nome do módulo é obrigatório',
            'name.unique' => 'Já existe um módulo com este nome',
        ];
    }
}
