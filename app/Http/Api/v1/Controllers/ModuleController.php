<?php

class ModuleController extends Controller
{
    /**
     * Lista todos os módulos
     */
    public function index(): JsonResponse
    {
        $modules = Module::all();
        return response()->json(['data' => $modules]);
    }

    /**
     * Obtém um módulo específico pelo ID
     */
    public function show(string $id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        return response()->json(['data' => $module]);
    }

    /**
     * Cria um novo módulo
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'show_in_menu' => 'boolean',
            'menu_position' => 'nullable|integer|min:0',
            'menu_icon' => 'nullable|string|max:50',
            'fields_schema' => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        $module = new Module([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'created_by_type' => CreatedByType::TENANT,
            'show_in_menu' => $request->input('show_in_menu', false),
            'menu_position' => $request->input('menu_position', 0),
            'menu_icon' => $request->input('menu_icon', 'document'),
        ]);

        // Se enviou um esquema de campos personalizado
        if ($request->has('fields_schema')) {
            $module->fields_schema = $request->input('fields_schema');
        }

        $module->save();

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
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'show_in_menu' => 'boolean',
            'menu_position' => 'nullable|integer|min:0',
            'menu_icon' => 'nullable|string|max:50',
            'fields_schema' => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        // Atualiza os campos permitidos
        if ($request->has('name')) {
            $module->name = $request->input('name');
        }

        if ($request->has('description')) {
            $module->description = $request->input('description');
        }

        if ($request->has('show_in_menu')) {
            $module->show_in_menu = $request->input('show_in_menu');
        }

        if ($request->has('menu_position')) {
            $module->menu_position = $request->input('menu_position');
        }

        if ($request->has('menu_icon')) {
            $module->menu_icon = $request->input('menu_icon');
        }

        // Se enviou um esquema de campos personalizado
        if ($request->has('fields_schema')) {
            $module->fields_schema = $request->input('fields_schema');
        }

        $module->save();

        return response()->json([
            'message' => 'Módulo atualizado com sucesso',
            'data' => $module
        ]);
    }

    /**
     * Remove um módulo existente
     */
    public function destroy(string $id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        // Primeiro verifica se existem itens associados
        $itemCount = $module->items()->count();

        if ($itemCount > 0) {
            return response()->json([
                'message' => 'Não é possível excluir o módulo porque existem itens associados a ele',
                'item_count' => $itemCount
            ], 422);
        }

        $module->delete();

        return response()->json(['message' => 'Módulo removido com sucesso']);
    }

    /**
     * Retorna os tipos de campos disponíveis
     */
    public function getFieldTypes(): JsonResponse
    {
        $fieldTypes = new ModuleFieldTypes();

        return response()->json([
            'types' => $fieldTypes->getSupportedTypes(),
            'relation_types' => $fieldTypes->getRelationTypes()
        ]);
    }

    /**
     * Adiciona um campo de relacionamento ao módulo
     */
    public function addRelationField(Request $request, string $id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'field_name' => 'required|string|regex:/^[a-zA-Z0-9_]+$/',
            'model_class' => 'required|string',
            'label' => 'required|string|max:255',
            'multiple' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        // Verifica se o campo já existe
        $fieldsSchema = $module->fields_schema;
        if (isset($fieldsSchema[$request->input('field_name')])) {
            return response()->json(['message' => 'O campo já existe no esquema do módulo'], 422);
        }

        // Verifica se a classe do modelo existe
        $modelClass = $request->input('model_class');
        if (!class_exists($modelClass)) {
            return response()->json(['message' => 'A classe do modelo especificada não existe'], 422);
        }

        // Adiciona o campo de relacionamento
        $module->addRelationField(
            $request->input('field_name'),
            $modelClass,
            $request->input('label'),
            $request->input('multiple', false)
        );

        $module->save();

        return response()->json([
            'message' => 'Campo de relacionamento adicionado com sucesso',
            'data' => $module->fields_schema
        ]);
    }

    /**
     * Remove um campo do esquema do módulo
     */
    public function removeField(Request $request, string $id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'field_name' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        $fieldName = $request->input('field_name');

        // Verifica se o campo existe
        $fieldsSchema = $module->fields_schema;
        if (!isset($fieldsSchema[$fieldName])) {
            return response()->json(['message' => 'O campo não existe no esquema do módulo'], 422);
        }

        // Remove o campo
        unset($fieldsSchema[$fieldName]);
        $module->fields_schema = $fieldsSchema;
        $module->save();

        return response()->json([
            'message' => 'Campo removido com sucesso',
            'data' => $module->fields_schema
        ]);
    }
}
declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenants\CreatedByType;
use App\Models\Tenants\Module;
use App\Support\Schema\ModuleFieldTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuleController extends Controller
{
    /**
     * Lista todos os módulos
     */
    public function index(): JsonResponse
    {
        $modules = Module::all();
        return response()->json(['data' => $modules]);
    }

    /**
     * Obtém um módulo específico pelo ID
     */
    public function show(string $id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        return response()->json(['data' => $module]);
    }

    /**
     * Cria um novo módulo
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'show_in_menu' => 'boolean',
            'menu_position' => 'nullable|integer|min:0',
            'menu_icon' => 'nullable|string|max:50',
            'fields_schema' => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        $module = new Module([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'created_by_type' => CreatedByType::TENANT,
            'show_in_menu' => $request->input('show_in_menu', false),
            'menu_position' => $request->input('menu_position', 0),
            'menu_icon' => $request->input('menu_icon', 'document'),
        ]);

        // Se enviou um esquema de campos personalizado
        if ($request->has('fields_schema')) {
            $module->fields_schema = $request->input('fields_schema');
        }

        $module->save();

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
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'show_in_menu' => 'boolean',
            'menu_position' => 'nullable|integer|min:0',
            'menu_icon' => 'nullable|string|max:50',
            'fields_schema' => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        // Atualiza os campos permitidos
        if ($request->has('name')) {
            $module->name = $request->input('name');
        }

        if ($request->has('description')) {
            $module->description = $request->input('description');
        }

        if ($request->has('show_in_menu')) {
            $module->show_in_menu = $request->input('show_in_menu');
        }

        if ($request->has('menu_position')) {
            $module->menu_position = $request->input('menu_position');
        }

        if ($request->has('menu_icon')) {
            $module->menu_icon = $request->input('menu_icon');
        }

        // Se enviou um esquema de campos personalizado
        if ($request->has('fields_schema')) {
            $module->fields_schema = $request->input('fields_schema');
        }

        $module->save();

        return response()->json([
            'message' => 'Módulo atualizado com sucesso',
            'data' => $module
        ]);
    }

    /**
     * Remove um módulo existente
     */
    public function destroy(string $id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        // Primeiro verifica se existem itens associados
        $itemCount = $module->items()->count();

        if ($itemCount > 0) {
            return response()->json([
                'message' => 'Não é possível excluir o módulo porque existem itens associados a ele',
                'item_count' => $itemCount
            ], 422);
        }

        $module->delete();

        return response()->json(['message' => 'Módulo removido com sucesso']);
    }

    /**
     * Retorna os tipos de campos disponíveis
     */
    public function getFieldTypes(): JsonResponse
    {
        $fieldTypes = new ModuleFieldTypes();

        return response()->json([
            'types' => $fieldTypes->getSupportedTypes(),
            'relation_types' => $fieldTypes->getRelationTypes()
        ]);
    }

    /**
     * Adiciona um campo de relacionamento ao módulo
     */
    public function addRelationField(Request $request, string $id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'field_name' => 'required|string|regex:/^[a-zA-Z0-9_]+$/',
            'model_class' => 'required|string',
            'label' => 'required|string|max:255',
            'multiple' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        // Verifica se o campo já existe
        $fieldsSchema = $module->fields_schema;
        if (isset($fieldsSchema[$request->input('field_name')])) {
            return response()->json(['message' => 'O campo já existe no esquema do módulo'], 422);
        }

        // Verifica se a classe do modelo existe
        $modelClass = $request->input('model_class');
        if (!class_exists($modelClass)) {
            return response()->json(['message' => 'A classe do modelo especificada não existe'], 422);
        }

        // Adiciona o campo de relacionamento
        $module->addRelationField(
            $request->input('field_name'),
            $modelClass,
            $request->input('label'),
            $request->input('multiple', false)
        );

        $module->save();

        return response()->json([
            'message' => 'Campo de relacionamento adicionado com sucesso',
            'data' => $module->fields_schema
        ]);
    }

    /**
     * Remove um campo do esquema do módulo
     */
    public function removeField(Request $request, string $id): JsonResponse
    {
        $module = Module::find($id);

        if (!$module) {
            return response()->json(['message' => 'Módulo não encontrado'], 404);
        }

        $validator = Validator::make($request->all(), [
            'field_name' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Dados inválidos', 'errors' => $validator->errors()], 422);
        }

        $fieldName = $request->input('field_name');

        // Verifica se o campo existe
        $fieldsSchema = $module->fields_schema;
        if (!isset($fieldsSchema[$fieldName])) {
            return response()->json(['message' => 'O campo não existe no esquema do módulo'], 422);
        }

        // Remove o campo
        unset($fieldsSchema[$fieldName]);
        $module->fields_schema = $fieldsSchema;
        $module->save();

        return response()->json([
            'message' => 'Campo removido com sucesso',
            'data' => $module->fields_schema
        ]);
    }
}
