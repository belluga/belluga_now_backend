<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Landlord\RoleTemplate;
use App\Models\Tenants\Role;
use App\Models\Tenants\TenantUser;

class PermissionService
{
    /**
     * Cria um papel com base em um template
     */
    public function createRoleFromTemplate(string $templateSlug, array $customizations = []): Role
    {
        $template = RoleTemplate::where('slug', $templateSlug)->first();

        if (!$template) {
            throw new \Exception("Template não encontrado");
        }

        $permissions = $this->mergePermissions(
            $template->permissions_schema,
            $customizations
        );

        return Role::create([
            'name' => $template->name,
            'description' => $template->description,
            'template_id' => $template->_id,
            'permissions' => $permissions
        ]);
    }

    /**
     * Verifica se um usuário tem uma permissão específica
     */
    public function can(TenantUser $user, string $moduleId, string $action, string $scope = 'all', array $context = []): bool
    {
        foreach ($user->accountRoles as $accountRole) {
            // Se temos um account_id no contexto, verifica se o papel pertence a essa conta
            if (isset($context['account_id']) && $accountRole->account_id !== $context['account_id']) {
                continue;
            }

            if ($this->checkPermission($accountRole->role, $moduleId, $action, $scope, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mescla permissões do template com customizações
     */
    private function mergePermissions(array $templateSchema, array $customizations): array
    {
        $permissions = [];

        foreach ($templateSchema as $moduleId => $modulePermissions) {
            $permission = [
                'module_id' => $moduleId,
                'actions' => []
            ];

            foreach ($modulePermissions as $section => $actions) {
                foreach ($actions as $action => $config) {
                    $scope = $config['scope'] ?? 'all';

                    // Verifica se existe customização para esta ação
                    if (isset($customizations[$moduleId][$section][$action])) {
                        $scope = $customizations[$moduleId][$section][$action]['scope'] ?? $scope;
                    }

                    $permission['actions']["$section.$action"] = [
                        'scope' => $scope
                    ];
                }
            }

            $permissions[] = $permission;
        }

        return $permissions;
    }

    /**
     * Verifica se um papel tem a permissão solicitada
     */
    private function checkPermission(Role $role, string $moduleId, string $action, string $scope, array $context): bool
    {
        $permission = collect($role->permissions)
            ->firstWhere('module_id', $moduleId);

        if (!$permission) {
            // Verifica permissões wildcard (modules.*)
            $permission = collect($role->permissions)
                ->firstWhere('module_id', 'modules.*');

            if (!$permission) {
                return false;
            }
        }

        // Verifica a seção e ação (ex: items.view, module.manage)
        $actionParts = explode('.', $action);
        if (count($actionParts) !== 2) {
            $action = "items.$action"; // Padrão para 'items' se não especificado
        }

        $actionConfig = $permission['actions'][$action] ?? null;

        if (!$actionConfig) {
            return false;
        }

        // Verifica escopo de permissão
        if ($actionConfig['scope'] === 'all') {
            return true;
        }

        if ($actionConfig['scope'] === 'account' && in_array($scope, ['account', 'owned'])) {
            return $this->checkAccountContext($context);
        }

        if ($actionConfig['scope'] === 'owned' && $scope === 'owned') {
            return $this->checkOwnershipContext($context);
        }

        return false;
    }

    /**
     * Verifica contexto de conta
     */
    private function checkAccountContext(array $context): bool
    {
        // Se não houver contexto de account_id, assume-se verdadeiro
        if (!isset($context['account_id'])) {
            return true;
        }

        $user = auth()->user();

        if (!$user) {
            return false;
        }

        $currentAccountId = app(AccountSessionManager::class)->getCurrentAccountId();

        if (!$currentAccountId) {
            return false;
        }

        return $context['account_id'] === $currentAccountId;
    }

    /**
     * Verifica contexto de propriedade
     */
    private function checkOwnershipContext(array $context): bool
    {
        if (!isset($context['user_id'])) {
            return false;
        }

        $user = auth()->user();

        if (!$user) {
            return false;
        }

        return $context['user_id'] === $user->id;
    }
}
