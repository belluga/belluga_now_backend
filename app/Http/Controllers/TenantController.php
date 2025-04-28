<?php

namespace App\Http\Controllers;

use App\Services\TenantSessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    protected $tenantSessionManager;

    public function __construct(TenantSessionManager $tenantSessionManager)
    {
        $this->tenantSessionManager = $tenantSessionManager;
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

    /**
     * Lista os tenants disponíveis para o usuário
     */
    public function listTenants()
    {
        $user = auth()->guard('landlord')->user();
        $tenants = $user->tenants;
        $currentTenantId = $this->tenantSessionManager->getCurrentTenantId();

        return view('tenants.list', [
            'tenants' => $tenants,
            'currentTenantId' => $currentTenantId
        ]);
    }
}
