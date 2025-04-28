<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Tenants\Module;
use App\Services\ModuleService;
use Illuminate\Foundation\Http\FormRequest;

class ModuleItemRequest extends FormRequest
{
    protected $moduleService;

    public function __construct(ModuleService $moduleService)
    {
        $this->moduleService = $moduleService;
        parent::__construct();
    }

    /**
     * Determine se o usuário está autorizado a fazer esta requisição.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Preparar os dados para validação.
     */
    protected function prepareForValidation()
    {
        // Certifique-se de que data é um array
        if (!$this->has('data')) {
            $this->merge(['data' => []]);
        }
    }

    /**
     * Obter as regras de validação que se aplicam à requisição.
     */
    public function rules(): array
    {
        $moduleId = $this->route('moduleId');
        $module = Module::findOrFail($moduleId);

        // Usar o ModuleService para gerar regras baseadas no schema
        return $this->moduleService->generateRules($module->fields_schema);
    }

    /**
     * Obter as mensagens de erro personalizadas para as regras de validação.
     */
    public function messages(): array
    {
        $moduleId = $this->route('moduleId');
        $module = Module::findOrFail($moduleId);

        return $this->moduleService->generateMessages($module->fields_schema);
    }

    /**
     * Manipular uma requisição de validação falha.
     */
    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new \Illuminate\Validation\ValidationException($validator, response()->json([
            'message' => 'Erro de validação',
            'errors' => $validator->errors()
        ], 422));
    }
}
