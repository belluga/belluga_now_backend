<?php

namespace App\Traits;

trait HasPermissions
{
    public function hasPermission(string $moduleId, string $action, string $scope = 'all', array $context = []): bool
    {
        return app(\App\Services\PermissionService::class)->can(
            $this,
            $moduleId,
            $action,
            $scope,
            $context
        );
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            $moduleId = $permission[0] ?? null;
            $action = $permission[1] ?? null;
            $scope = $permission[2] ?? 'all';
            $context = $permission[3] ?? [];

            if ($moduleId && $action && $this->hasPermission($moduleId, $action, $scope, $context)) {
                return true;
            }
        }

        return false;
    }

    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            $moduleId = $permission[0] ?? null;
            $action = $permission[1] ?? null;
            $scope = $permission[2] ?? 'all';
            $context = $permission[3] ?? [];

            if (!$moduleId || !$action || !$this->hasPermission($moduleId, $action, $scope, $context)) {
                return false;
            }
        }

        return true;
    }
}
