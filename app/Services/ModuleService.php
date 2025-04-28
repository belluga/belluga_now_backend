<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenants\Module;
use App\Models\Tenants\ModuleItem;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ModuleService
{
    /**
     * Valida os dados de um item de módulo baseado em seu fields_schema
     */
    public function validateModuleItem(Module $module, array $data): array
    {
        $rules = $this->generateRules($module->fields_schema);
        $messages = $this->generateMessages($module->fields_schema);

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return $validator->validated();
    }

    /**
     * Gera regras de validação baseadas no schema de campos
     */
    public function generateRules(array $schema): array
    {
        $rules = [];

        foreach ($schema['fields'] as $field) {
            $fieldName = "data.{$field['name']}";

            if ($field['type'] === 'repeater') {
                $rules[$fieldName] = 'array';

                if (isset($field['settings']['min_rows'])) {
                    $rules[$fieldName] .= '|min:' . $field['settings']['min_rows'];
                }

                if (isset($field['settings']['max_rows'])) {
                    $rules[$fieldName] .= '|max:' . $field['settings']['max_rows'];
                }

                // Regras para sub-campos do repeater
                foreach ($field['settings']['sub_fields'] as $subField) {
                    $subRules = $this->getFieldTypeRules($subField);
                    if (!empty($subRules)) {
                        $rules["$fieldName.*.{$subField['name']}"] = $subRules;
                    }
                }
            } else {
                $fieldRules = $this->getFieldTypeRules($field);
                if (!empty($fieldRules)) {
                    $rules[$fieldName] = $fieldRules;
                }
            }
        }

        return $rules;
    }

    /**
     * Gera mensagens de validação customizadas
     */
    private function generateMessages(array $schema): array
    {
        $messages = [];

        foreach ($schema['fields'] as $field) {
            $fieldName = "data.{$field['name']}";
            $label = $field['label'] ?? $field['name'];

            if ($field['required'] ?? false) {
                $messages["$fieldName.required"] = "O campo '$label' é obrigatório.";
            }

            if ($field['type'] === 'repeater') {
                if (isset($field['settings']['min_rows'])) {
                    $min = $field['settings']['min_rows'];
                    $messages["$fieldName.min"] = "O campo '$label' deve ter pelo menos $min item(s).";
                }

                if (isset($field['settings']['max_rows'])) {
                    $max = $field['settings']['max_rows'];
                    $messages["$fieldName.max"] = "O campo '$label' deve ter no máximo $max item(s).";
                }

                foreach ($field['settings']['sub_fields'] as $subField) {
                    $subLabel = $subField['label'] ?? $subField['name'];
                    if ($subField['required'] ?? false) {
                        $messages["$fieldName.*.{$subField['name']}.required"] = "O campo '$subLabel' é obrigatório.";
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Obtém regras de validação específicas para um tipo de campo
     */
    private function getFieldTypeRules(array $field): string
    {
        $rules = [];

        if ($field['required'] ?? false) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        switch ($field['type']) {
            case 'text':
                $rules[] = 'string';
                if (isset($field['settings']['max_length'])) {
                    $rules[] = 'max:' . $field['settings']['max_length'];
                }
                break;

            case 'number':
                $rules[] = 'numeric';
                if (isset($field['settings']['min'])) {
                    $rules[] = 'min:' . $field['settings']['min'];
                }
                if (isset($field['settings']['max'])) {
                    $rules[] = 'max:' . $field['settings']['max'];
                }
                break;

            case 'email':
                $rules[] = 'email';
                break;

            case 'date':
                $rules[] = 'date';
                break;

            case 'select':
                $rules[] = 'string';
                if (isset($field['settings']['options'])) {
                    $options = array_keys($field['settings']['options']);
                    $rules[] = 'in:' . implode(',', $options);
                }
                break;

            case 'multiselect':
                $rules[] = 'array';
                if (isset($field['settings']['options'])) {
                    $options = array_keys($field['settings']['options']);
                    $rules[] = 'in:' . implode(',', $options);
                }
                break;

            case 'boolean':
                $rules[] = 'boolean';
                break;

            case 'file':
            case 'image':
                $rules[] = 'string'; // O caminho do arquivo é salvo como string
                break;
        }

        return implode('|', $rules);
    }

    /**
     * Verifica se um item de módulo atende as condições de exibição de um campo
     */
    public function checkShowConditions(array $conditions, array $data): bool
    {
        if (empty($conditions)) {
            return true;
        }

        $operator = $conditions['operator'] ?? 'AND';
        $rules = $conditions['rules'] ?? [];

        if (empty($rules)) {
            return true;
        }

        $results = [];
        foreach ($rules as $rule) {
            $fieldName = $rule['field'];
            $fieldValue = $data[$fieldName] ?? null;
            $ruleOperator = $rule['operator'] ?? 'equals';
            $ruleValue = $rule['value'] ?? null;

            $results[] = $this->evaluateCondition($fieldValue, $ruleOperator, $ruleValue);
        }

        return $operator === 'AND'
            ? !in_array(false, $results, true)
            : in_array(true, $results, true);
    }

    /**
     * Avalia uma condição específica
     */
    private function evaluateCondition($fieldValue, string $operator, $ruleValue): bool
    {
        switch ($operator) {
            case 'equals':
                return $fieldValue == $ruleValue;

            case 'not_equals':
                return $fieldValue != $ruleValue;

            case 'contains':
                if (is_array($fieldValue)) {
                    return in_array($ruleValue, $fieldValue);
                }
                return is_string($fieldValue) && strpos($fieldValue, $ruleValue) !== false;

            case 'in':
                if (!is_array($ruleValue)) {
                    $ruleValue = [$ruleValue];
                }
                return in_array($fieldValue, $ruleValue);

            case 'not_in':
                if (!is_array($ruleValue)) {
                    $ruleValue = [$ruleValue];
                }
                return !in_array($fieldValue, $ruleValue);

            case 'empty':
                return empty($fieldValue);

            case 'not_empty':
                return !empty($fieldValue);

            default:
                return false;
        }
    }
}
