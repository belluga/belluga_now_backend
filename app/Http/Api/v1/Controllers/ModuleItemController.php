<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenants\Module;
use App\Models\Tenants\ModuleItem;
use App\Services\AccountSessionManager;
use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleItemController extends Controller
{
    protected $moduleService;
    protected $accountSessionManager;

    public function __construct(
        ModuleService $moduleService,
        AccountSessionManager $accountSessionManager
    ) {
        $this->moduleService = $moduleService;
        $this->accountSessionManager = $accountSessionManager;
    }

    /**
     * Lista todos os itens de um módulo
     */
    public function index(Request $request, string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        $query = $module->items();

        // Filtrar por account_id se não for admin
        $currentAccountId = $this->accountSessionManager->getCurrentAccountId();
        if ($currentAccountId) {
            $query->where('account_id', $currentAccountId);
        }

        // Filtros dinâmicos baseados no schema de campos
        if ($request->has('filter')) {
            foreach ($request->filter as $field => $value) {
                $query->where("data.$field", $value);
            }
        }

        // Ordenação
        $sortField = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');

        if (strpos($sortField, 'data.') === 0) {
            $query->orderBy($sortField, $sortDir);
        } else {
            $query->orderBy($sortField, $sortDir);
        }

        // Paginação
        $perPage = (int) $request->input('per_page', 15);
        $items = $query->paginate($perPage);

        return response()->json($items);
    }

    /**
     * Lista itens usando o slug do módulo ao invés do ID
     */
    public function indexBySlug(Request $request, string $slug): JsonResponse
    {
        $module = Module::where('slug', $slug)->firstOrFail();
        return $this->index($request, $module->_id);
    }

    /**
     * Mostra detalhes de um item específico
     */
    public function show(string $moduleId, string $id): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $item = ModuleItem::where('module_id', $moduleId)->findOrFail($id);

        return response()->json([
            'data' => $item
        ]);
    }

    /**
     * Mostra detalhes de um item usando o slug do módulo
     */
    public function showBySlug(string $slug, string $itemId): JsonResponse
    {
        $module = Module::where('slug', $slug)->firstOrFail();
        return $this->show($module->_id, $itemId);
    }

    /**
     * Cria um novo item de módulo
     */
    public function store(Request $request, string $moduleId): JsonResponse
    {
        $module = Module::findOrFail($moduleId);

        // Valida os dados baseado no schema de campos
        $validatedData = $this->moduleService->validateModuleItem($module, $request->all());

        // Adiciona informações básicas
        $validatedData['module_id'] = $moduleId;
        $validatedData['user_id'] = auth()->id();
        $validatedData['account_id'] = $this->accountSessionManager->getCurrentAccountId();

        $item = ModuleItem::create($validatedData);

        return response()->json([
            'message' => 'Item criado com sucesso',
            'data' => $item
        ], 201);
    }

    /**
     * Atualiza um item existente
     */
    public function update(Request $request, string $moduleId, string $id): JsonResponse
    {
        $module = Module::findOrFail($moduleId);
        $item = ModuleItem::where('module_id', $moduleId)->findOrFail($id);

        // Valida os dados baseado no schema de campos
        $validatedData = $this->moduleService->validateModuleItem($module, $request->all());

        $item->update($validatedData);

        return response()->json([
            'message' => 'Item atualizado com sucesso',
            'data' => $item
        ]);
    }

    /**
     * Remove um item
     */
    public function destroy(string $moduleId, string $id): JsonResponse
    {
        $item = ModuleItem::where('module_id', $moduleId)->findOrFail($id);
        $item->delete();

        return response()->json([
            'message' => 'Item excluído com sucesso'
        ]);
    }

    /**
     * Upload de arquivo para um campo do tipo file/image
     */
    public function uploadFile(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'module_id' => 'required|exists:tenants.modules,_id',
            'field_name' => 'required|string'
        ]);

        $module = Module::findOrFail($request->module_id);

        // Verifica se o campo existe no schema
        $fieldExists = false;
        foreach ($module->fields_schema['fields'] as $field) {
            if ($field['name'] === $request->field_name && in_array($field['type'], ['file', 'image'])) {
                $fieldExists = true;
                break;
            }
        }

        if (!$fieldExists) {
            return response()->json([
                'message' => 'Campo não encontrado ou não é do tipo arquivo/imagem'
            ], 422);
        }

        // Define o diretório baseado no módulo e tenant
        $path = $request->file('file')->store(
            "modules/{$module->slug}/" . date('Y/m'),
            'public'
        );

        return response()->json([
            'url' => $path,
            'full_url' => asset('storage/' . $path)
        ]);
    }
}
