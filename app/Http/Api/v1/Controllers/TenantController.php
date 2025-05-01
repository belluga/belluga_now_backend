<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\TenantRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Services\TenantSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class TenantController extends Controller
{
    protected $tenantSessionManager;

    public function __construct(TenantSessionManager $tenantSessionManager)
    {
        $this->tenantSessionManager = $tenantSessionManager;
    }

    public function index(Request $request): LengthAwarePaginator
    {
        $user = auth()->guard('sanctum')->user();
        return $user->tenants()->with('domains')->paginate($request->get('per_page', 15));
    }

    public function store(TenantRequest $request): JsonResponse
    {

        $user = auth()->guard('sanctum')->user();
        $tenant = $user->tenants()->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $tenant
        ], 201);

    }

    public function show(string $tenant_slug): JsonResponse
    {

        $user = auth()->guard('sanctum')->user();
        $tenant = $user->tenants()->where('slug', $tenant_slug)->first();

        if($tenant){
            return response()->json($tenant);
        }

        return response()->json([
            'message' => "Tenant não encontrado.",
            'errors' => [
                'tenant ' => ["O tenant solicitado não existe."
                ]
            ]
        ],
        404);
    }


    /**
     * Altera o tenant atual do usuário na sessão
     */
    public function switchTenant(Request $request, string $tenantId): RedirectResponse
    {
        $user = auth()->guard('landlord')->user();

        // Verifica se o usuário tem acesso a este tenant
        $hasTenant = $user->tenants()->where('id', $tenantId)->exists();

        if (!$hasTenant) {
            return redirect()->back()->with('error', 'Você não tem acesso a este tenant');
        }

        // Define o tenant atual na sessão
        $this->tenantSessionManager->setCurrentTenantId($tenantId);

        return redirect()->back()->with('success', 'Tenant alterado com sucesso');
    }
}
