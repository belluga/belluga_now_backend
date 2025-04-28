<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AccountSessionManager;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;

class CheckModulePermission
{
    protected $permissionService;
    protected $accountSessionManager;

    public function __construct(
        PermissionService $permissionService,
        AccountSessionManager $accountSessionManager
    ) {
        $this->permissionService = $permissionService;
        $this->accountSessionManager = $accountSessionManager;
    }

    /**
     * Verifica se o usuário possui permissão para determinado módulo e ação
     */
    public function handle(Request $request, Closure $next, string $moduleId, string $action, string $scope = 'all')
    {
        $user = auth()->user();

        if (!$user) {
            abort(401, 'Não autenticado');
        }

        $context = [
            'account_id' => $request->route('account_id') ?? $this->accountSessionManager->getCurrentAccountId(),
            'user_id' => $request->route('user_id')
        ];

        if (!$this->permissionService->can($user, $moduleId, $action, $scope, $context)) {
            abort(403, 'Acesso negado');
        }

        return $next($request);
    }
}
