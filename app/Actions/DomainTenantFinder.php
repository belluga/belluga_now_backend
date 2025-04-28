<?php

namespace App\Actions;

use Illuminate\Http\Request;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

class DomainTenantFinder extends TenantFinder
{
    public function findForRequest(Request $request): ?IsTenant
    {
        // 1. Verificar se a requisição veio do App Flutter
        if ($request->hasHeader('X-App-Domain')) {
            // Vindo do Flutter App, usando o domínio fornecido no cabeçalho
            $appDomain = $request->header('X-App-Domain');
            return $this->findTenantByAppDomain($appDomain);
        }

        // 2. Verificar por domínio da requisição web
        $host = $request->getHost();

        // Primeiro tenta como app domain (caso esteja acessando o app via navegador)
        $tenant = $this->findTenantByAppDomain($host);

        // Se não encontrar, tenta como domínio web normal
        if (!$tenant) {
            $tenant = $this->findTenantByWebDomain($host);
        }

        return $tenant;
    }

    /**
     * Encontra um tenant pelo domínio de aplicativo
     *
     * @param string $domain
     * @return IsTenant|null
     */
    protected function findTenantByAppDomain(string $domain): ?IsTenant
    {
        return app(IsTenant::class)::where('app_domains', 'all', [$domain])->first();
    }

    /**
     * Encontra um tenant pelo domínio web
     *
     * @param string $domain
     * @return IsTenant|null
     */
    protected function findTenantByWebDomain(string $domain): ?IsTenant
    {
        return app(IsTenant::class)::where('domains', 'all', [$domain])->first();
    }
}
