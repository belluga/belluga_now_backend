<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenants\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    /**
     * Lista todos os módulos
     */
    public function index(Request $request): JsonResponse
    {
        $modules = Module::all();

        return response()->json([
            'data' => $modules
        ]);
    }

    /**
     * Mostra detalhes de um módulo específico
     */
    public function show(string $id): JsonResponse
    {
        $module = Module::findOrFail($id);

        return response()->json([
            'data' => $module
        ]);
    }

    /**
     * Cria um novo módulo
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'show_in_menu' => 'boolean',
            'menu_position' => 'nullable|integer',
            'menu_icon' => 'nullable|string',
            'fields_schema' => 'required|array',
            'permissions_schema' => 'nullable|array'
        ]);

        // Adiciona permissões padrão se não fornecidas
        if (!isset($validated['permissions_schema'])) {
            $moduleInstance = new Module();
            $validated['permissions_schema'] = $moduleInstance->getDefaultPermissionsSchema();
        }

        $module = Module::create($validated);

        return response()->json([
            'message' => 'Módulo criado com sucesso',
            'data' => $module
        ], 201);
    }

    /**
     * Atualiza um módulo existente
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $module = Module::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'show_in_menu' => 'boolean',
            'menu_position' => 'nullable|integer',
            'menu_icon' => 'nullable|string',
            'fields_schema' => 'array',
            'permissions_schema' => 'array'
        ]);

        $module->update($validated);

        return response()->json([
            'message' => 'Módulo atualizado com sucesso',
            'data' => $module
        ]);
    }

    /**
     * Remove um módulo
     */
    public function destroy(string $id): JsonResponse
    {
        $module = Module::findOrFail($id);

        // Verificar se o módulo tem itens
        if ($module->items()->count() > 0) {
            return response()->json([
                'message' => 'Não é possível excluir este módulo pois existem itens associados a ele'
            ], 422);
        }

        $module->delete();

        return response()->json([
            'message' => 'Módulo excluído com sucesso'
        ]);
    }

    /**
     * Retorna os tipos de campos suportados
     */
    public function fieldTypes(): JsonResponse
    {
        $moduleInstance = new Module();

        return response()->json([
            'data' => $moduleInstance->getSupportedFieldTypes()
        ]);
    }
}
