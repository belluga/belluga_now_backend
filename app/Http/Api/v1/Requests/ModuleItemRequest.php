<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Requests;

use App\Models\Tenants\Module;
use Illuminate\Foundation\Http\FormRequest;

class ModuleItemRequest extends FormRequest
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
     */
    public function rules(): array
    {
        $moduleId = $this->route('moduleId');
        $module = Module::findOrFail($moduleId);

        $rules = [];

        // Para cada campo no esquema, cria uma regra de validação
        foreach ($module->fields_schema as $fieldName => $fieldConfig) {
            // Ignora campos de relacionamento, eles serão validados separadamente
            if (isset($fieldConfig['type']) && in_array($fieldConfig['type'], ['relation', 'relations'])) {
                continue;
            }

            $fieldRules = [];

            // Campo obrigatório
            if (isset($fieldConfig['required']) && $fieldConfig['required']) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            // Regras específicas por tipo
            if (isset($fieldConfig['type'])) {
                switch ($fieldConfig['type']) {
                    case 'text':
                        $fieldRules[] = 'string';
                        if (isset($fieldConfig['max'])) {
                            $fieldRules[] = "max:{$fieldConfig['max']}";
                        }
                        break;

                    case 'number':
                        $fieldRules[] = 'numeric';
                        if (isset($fieldConfig['min'])) {
                            $fieldRules[] = "min:{$fieldConfig['min']}";
                        }
                        if (isset($fieldConfig['max'])) {
                            $fieldRules[] = "max:{$fieldConfig['max']}";
                        }
                        break;

                    case 'boolean':
                        $fieldRules[] = 'boolean';
                        break;

                    case 'date':
                        $fieldRules[] = 'date';
                        break;

                    case 'datetime':
                        $fieldRules[] = 'date';
                        break;

                    case 'select':
                        if (isset($fieldConfig['options'])) {
                            $fieldRules[] = 'in:' . implode(',', array_keys($fieldConfig['options']));
                        }
                        break;

                    case 'multiselect':
                        $fieldRules[] = 'array';
                        if (isset($fieldConfig['options'])) {
                            $fieldRules[] = 'in_array:' . implode(',', array_keys($fieldConfig['options']));
                        }
                        break;
                }
            }

            if (!empty($fieldRules)) {
                $rules[$fieldName] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        $moduleId = $this->route('moduleId');
        $module = Module::findOrFail($moduleId);

        $messages = [];

        // Para cada campo no esquema, cria mensagens personalizadas
        foreach ($module->fields_schema as $fieldName => $fieldConfig) {
            // Ignora campos de relacionamento
            if (isset($fieldConfig['type']) && in_array($fieldConfig['type'], ['relation', 'relations'])) {
                continue;
            }

            $label = $fieldConfig['label'] ?? $fieldName;

            // Campo obrigatório
            if (isset($fieldConfig['required']) && $fieldConfig['required']) {
                $messages["{$fieldName}.required"] = "O campo {$label} é obrigatório.";
            }

            // Mensagens específicas por tipo
            if (isset($fieldConfig['type'])) {
                switch ($fieldConfig['type']) {
                    case 'text':
                        $messages["{$fieldName}.string"] = "O campo {$label} deve ser um texto.";
                        if (isset($fieldConfig['max'])) {
                            $messages["{$fieldName}.max"] = "O campo {$label} não pode ter mais de {$fieldConfig['max']} caracteres.";
                        }
                        break;

                    case 'number':
                        $messages["{$fieldName}.numeric"] = "O campo {$label} deve ser um número.";
                        if (isset($fieldConfig['min'])) {
                            $messages["{$fieldName}.min"] = "O campo {$label} deve ser pelo menos {$fieldConfig['min']}.";
                        }
                        if (isset($fieldConfig['max'])) {
                            $messages["{$fieldName}.max"] = "O campo {$label} não pode ser maior que {$fieldConfig['max']}.";
                        }
                        break;

                    case 'boolean':
                        $messages["{$fieldName}.boolean"] = "O campo {$label} deve ser verdadeiro ou falso.";
                        break;

                    case 'date':
                    case 'datetime':
                        $messages["{$fieldName}.date"] = "O campo {$label} deve ser uma data válida.";
                        break;

                    case 'select':
                        if (isset($fieldConfig['options'])) {
                            $messages["{$fieldName}.in"] = "O valor selecionado para {$label} é inválido.";
                        }
                        break;

                    case 'multiselect':
                        $messages["{$fieldName}.array"] = "O campo {$label} deve ser uma lista de valores.";
                        if (isset($fieldConfig['options'])) {
                            $messages["{$fieldName}.in_array"] = "Um dos valores selecionados para {$label} é inválido.";
                        }
                        break;
                }
            }
        }

        return $messages;
    }
}
