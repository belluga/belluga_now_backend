<?php

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\TenantLandlordUserAttachRequest;
use App\Http\Api\v1\Requests\TenantRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Services\TenantSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;

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

        try {
            $tenant = $user->tenants()->create($request->validated());

            return response()->json([
                'data' => $tenant
            ], 201);

        } catch (BulkWriteException $e) {
            if (str_contains($e->getMessage(), 'E11000')) {
                return response()->json([
                    'message' => 'Tenant already exists.',
                    'errors' => ['tenant' => ["Tenant already exists."]]
                ], 422);
            }

            return response()->json([
                'message' => "Something went wrong when trying to create the tenant.",
                'errors' => ['tenant' => ["Something went wrong when trying to create the tenant."]]
            ], 422);
        }
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

    public function update(TenantRequest $request, string $tenant_slug): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $tenant = $user->tenants()->where('slug', $tenant_slug)->first();
        $tenant->update($request->validated());

        return response()->json([
            'data' => $tenant
        ], 200);
    }

    protected function destroyTokenCreate(): string
    {
        $user = auth()->guard('sanctum')->user();
        $token = $user->createToken('tenant-delete-confirmation');
        return $token->plainTextToken;
    }

    protected function destroyTokenFind(): bool
    {
        $user = auth()->guard('sanctum')->user();
        $received_token = request()->bearerToken();

        $destruction_token = $user->tokens()
            ->where('name', 'tenant-delete-confirmation')
            ->where('token', hash('sha256', $received_token))
            ->first();

        if (!$destruction_token) {
            return false;
        }

        $destruction_token->delete();
        return true;
    }

    public function destroy(string $tenant_slug): JsonResponse
    {
        $user = auth()->guard('sanctum')->user();
        $tenant = $user->tenants()->where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json([
                'message' => "Tenant não encontrado.",
                'errors' => [
                    'tenant' => ["O tenant solicitado não existe."]
                ]
            ], 404);
        }

        if (!$this->destroyTokenFind()) {
            $destruction_token = $this->destroyTokenCreate();
            return response()->json([
                'message' => "CUIDADO: Essa ação não poderá ser desfeita. Para confirmar a remoção utilize o 'destruction_token' em sua requisição.",
                'destruction_token' => $destruction_token,
            ], 200);
        }

        DB::beginTransaction();
        try {
            $tenant->domains()->delete();
            $tenant->users()->detach();
            $tenant->delete();
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => "Erro ao desfazer relacionamentos.",
                'errors' => [
                    'tenant' => ["Ocorreu um erro ao desfazer relacionamentos com o tenant. Tente novamente mais tarde."]
                ]
            ], 422);
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Tenant deletado com sucesso'
        ], 200);
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
