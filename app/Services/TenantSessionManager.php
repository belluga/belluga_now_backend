<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;

class TenantSessionManager
{
    /**
     * Chave usada para armazenar o ID do tenant atual na sessão
     */
    const SESSION_KEY = 'current_tenant_id';

    /**
     * Obtém o ID do tenant atual a partir da sessão
     */
    public function getCurrentTenantId(): ?string
    {
        return Session::get(self::SESSION_KEY);
    }

    /**
     * Define o ID do tenant atual na sessão
     */
    public function setCurrentTenantId(?string $tenantId): void
    {
        if ($tenantId) {
            Session::put(self::SESSION_KEY, $tenantId);
        } else {
            Session::forget(self::SESSION_KEY);
        }
    }

    /**
     * Remove o ID do tenant atual da sessão
     */
    public function clearCurrentTenantId(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
