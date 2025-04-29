<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Controllers\Traits\HasAccountInSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModuleItemRequest;
use App\Models\Tenants\Account;
use App\Models\Tenants\Module;
use App\Models\Tenants\ModuleItem;
use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ModuleItemController extends Controller
{
    use HasAccountInSlug;

    public function __construct(
        protected readonly ModuleService $moduleService
    ) {}

    /**
     * Lista todos os itens de um módulo específico
     *
     * @param string $moduleId ID do módulo
     * @return JsonResponse
     */
    public function index(Request $request, string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $query = ModuleItem::where('module_id', $moduleId);

        // Filtragem por campos
        if ($request->has('filter') && is_array($request->filter)) {
            foreach ($request->filter as $field => $value) {
                if (str_contains($field, '.')) {
                    // Filtro para campos aninhados (data.campo)
                    $query->where("data.$field", $value);
                } else {
                    $query->where("data.$field", $value);
                }
            }
        }

        // Filtragem por texto
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search, $module) {
                foreach ($module->fields_schema['fields'] as $field) {
                    if (in_array($field['type'], ['text', 'email', 'select', 'textarea'])) {
                        $q->orWhere("data.{$field['name']}", 'like', "%$search%");
                    }
                }
            });
        }

        // Ordenação
        $sortField = $request->input('sort_by', '_id');
        $sortDirection = $request->input('sort_dir', 'desc');

        if (str_starts_with($sortField, 'data.')) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('data.' . $sortField, $sortDirection);
        }

        // Paginação
        $perPage = (int) $request->input('per_page', 20);
        $items = $query->paginate($perPage);

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
                'total' => $items->total(),
                'per_page' => $items->perPage(),
            ],
            'module' => [
                'id' => $module->_id,
                'name' => $module->name,
                'schema' => $module->fields_schema,
            ]
        ]);
    }

    /**
     * Exibe um item específico de um módulo
     *
     * @param string $moduleId ID do módulo
     * @param string $itemId ID do item
     * @return JsonResponse
     */
    public function show(string $moduleId, string $itemId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $item = ModuleItem::where('module_id', $moduleId)
            ->where('_id', $itemId)
            ->firstOrFail();

        return response()->json([
            'data' => $item,
            'module' => [
                'id' => $module->_id,
                'name' => $module->name,
                'schema' => $module->fields_schema,
            ]
        ]);
    }

    /**
     * Cria um novo item para um módulo
     *
     * @param ModuleItemRequest $request
     * @param string $moduleId ID do módulo
     * @return JsonResponse
     */
    public function store(ModuleItemRequest $request, string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        // Validação dos dados conforme o schema do módulo
        $data = $request->validated();

        DB::beginTransaction();

        try {
            // Processar uploads de arquivos, se houver
            $data = $this->processFileUploads($data, $module);

            // Processar campos que podem precisar de tratamento especial
            $data = $this->processModuleItemFields($data, $module);

            // Criar o item do módulo
            $item = new ModuleItem([
                'module_id' => $moduleId,
                'data' => $data['data'],
                'account_id' => $this->account->_id ?? null,
                'created_by' => Auth::id()
            ]);

            $item->save();

            DB::commit();

            return response()->json([
                'message' => 'Item criado com sucesso',
                'data' => $item
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao criar item de módulo: ' . $e->getMessage(), [
                'module_id' => $moduleId,
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Erro ao criar o item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Atualiza um item de módulo existente
     *
     * @param ModuleItemRequest $request
     * @param string $moduleId ID do módulo
     * @param string $itemId ID do item
     * @return JsonResponse
     */
    public function update(ModuleItemRequest $request, string $moduleId, string $itemId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $item = ModuleItem::where('module_id', $moduleId)
            ->where('_id', $itemId)
            ->firstOrFail();

        // Validação dos dados conforme o schema do módulo
        $data = $request->validated();

        DB::beginTransaction();

        try {
            // Processar uploads de arquivos, se houver
            $data = $this->processFileUploads($data, $module, $item);

            // Processar campos que podem precisar de tratamento especial
            $data = $this->processModuleItemFields($data, $module);

            // Atualizar os dados do item
            $item->data = $data['data'];
            $item->updated_by = Auth::id();
            $item->save();

            DB::commit();

            return response()->json([
                'message' => 'Item atualizado com sucesso',
                'data' => $item
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao atualizar item de módulo: ' . $e->getMessage(), [
                'module_id' => $moduleId,
                'item_id' => $itemId,
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Erro ao atualizar o item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove um item de módulo
     *
     * @param string $moduleId ID do módulo
     * @param string $itemId ID do item
     * @return JsonResponse
     */
    public function destroy(string $moduleId, string $itemId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $item = ModuleItem::where('module_id', $moduleId)
            ->where('_id', $itemId)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Remover arquivos associados, se houver
            $this->removeItemFiles($item, $module);

            // Excluir o item
            $item->delete();

            DB::commit();

            return response()->json([
                'message' => 'Item removido com sucesso'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao remover item de módulo: ' . $e->getMessage(), [
                'module_id' => $moduleId,
                'item_id' => $itemId,
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Erro ao remover o item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Duplica um item de módulo existente
     *
     * @param string $moduleId ID do módulo
     * @param string $itemId ID do item a ser duplicado
     * @return JsonResponse
     */
    public function duplicate(string $moduleId, string $itemId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $originalItem = ModuleItem::where('module_id', $moduleId)
            ->where('_id', $itemId)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Criar uma cópia dos dados
            $newData = $originalItem->data;

            // Modificar campos únicos ou adicionar sufixo para indicar que é uma cópia
            foreach ($module->fields_schema['fields'] as $field) {
                if ($field['type'] === 'text' && isset($newData[$field['name']])) {
                    // Adicionar " (cópia)" a campos de texto que podem ser títulos
                    if (Str::contains(strtolower($field['name']), ['nome', 'titulo', 'title', 'name'])) {
                        $newData[$field['name']] = $newData[$field['name']] . ' (cópia)';
                    }
                }

                // Gerar novos IDs para campos que precisam ser únicos
                if (($field['unique'] ?? false) && $field['type'] === 'text') {
                    if (isset($newData[$field['name']])) {
                        $newData[$field['name']] = $newData[$field['name']] . '-' . Str::random(4);
                    }
                }

                // Tratar campos de arquivo para duplicá-los se necessário
                if (in_array($field['type'], ['file', 'image']) && isset($newData[$field['name']])) {
                    $filePath = $newData[$field['name']];
                    if (!empty($filePath)) {
                        // Duplicar o arquivo
                        $newFilePath = $this->duplicateFile($filePath);
                        $newData[$field['name']] = $newFilePath;
                    }
                }
            }

            // Criar o novo item com os dados duplicados
            $newItem = new ModuleItem([
                'module_id' => $moduleId,
                'data' => $newData,
                'account_id' => $originalItem->account_id,
                'created_by' => Auth::id()
            ]);

            $newItem->save();

            DB::commit();

            return response()->json([
                'message' => 'Item duplicado com sucesso',
                'data' => $newItem
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao duplicar item de módulo: ' . $e->getMessage(), [
                'module_id' => $moduleId,
                'item_id' => $itemId,
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Erro ao duplicar o item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lista todos os itens de módulo relacionados a uma conta específica
     *
     * @param string $account_slug Slug da conta
     * @param string $moduleId ID do módulo
     * @return JsonResponse
     */
    public function accountModuleItems(Request $request, string $account_slug, string $moduleId): JsonResponse
    {
        $this->extractAccountFromSlug();

        $module = Module::findOrFail($moduleId);

        $query = ModuleItem::where('module_id', $moduleId)
            ->where('account_id', $this->account->_id);

        // Adicionar filtros, ordenação e paginação como no método index
        // Filtragem por campos
        if ($request->has('filter') && is_array($request->filter)) {
            foreach ($request->filter as $field => $value) {
                if (str_contains($field, '.')) {
                    $query->where("data.$field", $value);
                } else {
                    $query->where("data.$field", $value);
                }
            }
        }

        // Ordenação
        $sortField = $request->input('sort_by', '_id');
        $sortDirection = $request->input('sort_dir', 'desc');

        if (str_starts_with($sortField, 'data.')) {
            $query->orderBy($sortField, $sortDirection);
        } else {
            $query->orderBy('data.' . $sortField, $sortDirection);
        }

        // Paginação
        $perPage = (int) $request->input('per_page', 20);
        $items = $query->paginate($perPage);

        return response()->json([
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
                'total' => $items->total(),
                'per_page' => $items->perPage(),
            ],
            'module' => [
                'id' => $module->_id,
                'name' => $module->name,
                'schema' => $module->fields_schema,
            ],
            'account' => [
                'id' => $this->account->_id,
                'name' => $this->account->name,
                'slug' => $this->account->slug
            ]
        ]);
    }

    /**
     * Processa uploads de arquivos
     *
     * @param array $data Dados do formulário
     * @param Module $module Módulo relacionado
     * @param ModuleItem|null $item Item existente (para atualizações)
     * @return array Dados processados
     */
    protected function processFileUploads(array $data, Module $module, ?ModuleItem $item = null): array
    {
        // Identificar campos de arquivo no schema
        $fileFields = [];
        foreach ($module->fields_schema['fields'] as $field) {
            if (in_array($field['type'], ['file', 'image'])) {
                $fileFields[] = $field['name'];
            }

            // Processar campos de arquivo em repeaters
            if ($field['type'] === 'repeater' && isset($field['settings']['sub_fields'])) {
                foreach ($field['settings']['sub_fields'] as $subField) {
                    if (in_array($subField['type'], ['file', 'image'])) {
                        $fileFields[] = $field['name'] . '.' . $subField['name'];
                    }
                }
            }
        }

        // Não há campos de arquivo para processar
        if (empty($fileFields)) {
            return $data;
        }

        // Processar cada campo de arquivo
        foreach ($fileFields as $fieldPath) {
            $parts = explode('.', $fieldPath);

            // Campo simples
            if (count($parts) === 1 && request()->hasFile("data.{$parts[0]}")) {
                $file = request()->file("data.{$parts[0]}");

                // Remover arquivo antigo se estiver atualizando
                if ($item && isset($item->data[$parts[0]]) && !empty($item->data[$parts[0]])) {
                    $this->removeFile($item->data[$parts[0]]);
                }

                // Fazer upload do novo arquivo
                $path = $file->store('module-files/' . $module->_id, 'public');
                $data['data'][$parts[0]] = $path;
            }
            // Campo em repeater
            else if (count($parts) === 2 && isset($data['data'][$parts[0]]) && is_array($data['data'][$parts[0]])) {
                foreach ($data['data'][$parts[0]] as $index => $repeaterItem) {
                    if (request()->hasFile("data.{$parts[0]}.{$index}.{$parts[1]}")) {
                        $file = request()->file("data.{$parts[0]}.{$index}.{$parts[1]}");

                        // Remover arquivo antigo se estiver atualizando
                        if ($item &&
                            isset($item->data[$parts[0]][$index][$parts[1]]) &&
                            !empty($item->data[$parts[0]][$index][$parts[1]])
                        ) {
                            $this->removeFile($item->data[$parts[0]][$index][$parts[1]]);
                        }

                        // Fazer upload do novo arquivo
                        $path = $file->store('module-files/' . $module->_id . '/repeater', 'public');
                        $data['data'][$parts[0]][$index][$parts[1]] = $path;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Remove um arquivo do storage
     *
     * @param string $path Caminho do arquivo
     * @return bool
     */
    protected function removeFile(string $path): bool
    {
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->delete($path);
        }

        return false;
    }

    /**
     * Remove todos os arquivos associados a um item
     *
     * @param ModuleItem $item Item do módulo
     * @param Module $module Módulo relacionado
     * @return void
     */
    protected function removeItemFiles(ModuleItem $item, Module $module): void
    {
        // Identificar campos de arquivo no schema
        foreach ($module->fields_schema['fields'] as $field) {
            if (in_array($field['type'], ['file', 'image']) && isset($item->data[$field['name']])) {
                $this->removeFile($item->data[$field['name']]);
            }

            // Processar campos de arquivo em repeaters
            if ($field['type'] === 'repeater' && isset($field['settings']['sub_fields']) && isset($item->data[$field['name']])) {
                foreach ($item->data[$field['name']] as $repeaterItem) {
                    foreach ($field['settings']['sub_fields'] as $subField) {
                        if (in_array($subField['type'], ['file', 'image']) && isset($repeaterItem[$subField['name']])) {
                            $this->removeFile($repeaterItem[$subField['name']]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Duplica um arquivo no storage
     *
     * @param string $originalPath Caminho do arquivo original
     * @return string Caminho do novo arquivo
     * @throws \Exception
     */
    protected function duplicateFile(string $originalPath): string
    {
        if (!Storage::disk('public')->exists($originalPath)) {
            throw new \Exception("Arquivo original não encontrado: $originalPath");
        }

        $pathInfo = pathinfo($originalPath);
        $newFilename = $pathInfo['filename'] . '-copy-' . Str::random(6);

        if (isset($pathInfo['extension'])) {
            $newFilename .= '.' . $pathInfo['extension'];
        }

        $newPath = $pathInfo['dirname'] . '/' . $newFilename;

        $content = Storage::disk('public')->get($originalPath);
        Storage::disk('public')->put($newPath, $content);

        return $newPath;
    }

    /**
     * Processa campos especiais de um item de módulo
     *
     * @param array $data Dados do formulário
     * @param Module $module Módulo relacionado
     * @return array Dados processados
     */
    protected function processModuleItemFields(array $data, Module $module): array
    {
        foreach ($module->fields_schema['fields'] as $field) {
            $fieldName = $field['name'];

            // Processar campos com valor automático
            if (($field['auto'] ?? false) && !isset($data['data'][$fieldName])) {
                switch ($field['auto_type'] ?? '') {
                    case 'uuid':
                        $data['data'][$fieldName] = (string) Str::uuid();
                        break;
                    case 'slug':
                        if (isset($field['source_field']) && isset($data['data'][$field['source_field']])) {
                            $data['data'][$fieldName] = Str::slug($data['data'][$field['source_field']]);
                        }
                        break;
                    case 'timestamp':
                        $data['data'][$fieldName] = now()->toDateTimeString();
                        break;
                    case 'current_user':
                        $data['data'][$fieldName] = Auth::id();
                        break;
                    case 'current_account':
                        if (isset($this->account)) {
                            $data['data'][$fieldName] = $this->account->_id;
                        }
                        break;
                }
            }

            // Processar campos de tipo específico
            switch ($field['type']) {
                case 'json':
                    if (isset($data['data'][$fieldName]) && is_string($data['data'][$fieldName])) {
                        try {
                            $data['data'][$fieldName] = json_decode($data['data'][$fieldName], true);
                        } catch (\Exception $e) {
                            // Manter como string se não for um JSON válido
                        }
                    }
                    break;

                case 'password':
                    if (isset($data['data'][$fieldName]) && !empty($data['data'][$fieldName])) {
                        // Apenas atualiza a senha se uma nova foi fornecida
                        $data['data'][$fieldName] = bcrypt($data['data'][$fieldName]);
                    } else {
                        // Se está vazio e é uma atualização, manter a senha anterior
                        unset($data['data'][$fieldName]);
                    }
                    break;
            }
        }

        return $data;
    }
}
