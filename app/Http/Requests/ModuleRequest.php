<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ModuleRequest extends FormRequest
{
    /**
     * Determine se o usuário está autorizado a fazer esta requisição.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Obter as regras de validação que se aplicam à requisição.
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'show_in_menu' => 'boolean',
            'menu_position' => 'nullable|integer',
            'menu_icon' => 'nullable|string',
            'settings' => 'nullable|array',
        ];

        // Regras específicas para fields_schema
        $rules['fields_schema'] = 'required|array';
        $rules['fields_schema.fields'] = 'required|array';
        $rules['fields_schema.fields.*.name'] = 'required|string|alpha_dash';
        $rules['fields_schema.fields.*.type'] = 'required|string|in:text,textarea,rich_text,number,date,datetime,boolean,select,multiselect,file,image,repeater';
        $rules['fields_schema.fields.*.label'] = 'required|string';
        $rules['fields_schema.fields.*.required'] = 'boolean';
        $rules['fields_schema.fields.*.settings'] = 'nullable|array';
        $rules['fields_schema.fields.*.show_conditions'] = 'nullable|array';

        // Regras para campos do tipo repeater
        $rules['fields_schema.fields.*.settings.sub_fields'] = 'required_if:fields_schema.fields.*.type,repeater|array';
        $rules['fields_schema.fields.*.settings.min_rows'] = 'nullable|integer|min:0';
        $rules['fields_schema.fields.*.settings.max_rows'] = 'nullable|integer|min:1';

        // Opções para select/multiselect
        $rules['fields_schema.fields.*.settings.options'] = 'required_if:fields_schema.fields.*.type,select,multiselect|array';

        return $rules;
    }

    /**
     * Obter as mensagens de erro personalizadas.
     */
    public function messages(): array
    {
        return [
            'fields_schema.required' => 'O schema de campos é obrigatório',
            'fields_schema.fields.required' => 'Você deve definir pelo menos um campo',
            'fields_schema.fields.*.name.required' => 'Todos os campos devem ter um nome',
            'fields_schema.fields.*.name.alpha_dash' => 'O nome do campo deve conter apenas letras, números, traços e underscores',
            'fields_schema.fields.*.type.required' => 'Todos os campos devem ter um tipo',
            'fields_schema.fields.*.type.in' => 'Tipo de campo inválido',
            'fields_schema.fields.*.label.required' => 'Todos os campos devem ter um rótulo',
            'fields_schema.fields.*.settings.sub_fields.required_if' => 'Campos do tipo repeater precisam definir sub_fields',
            'fields_schema.fields.*.settings.options.required_if' => 'Campos do tipo select ou multiselect precisam definir options'
        ];
    }
}
