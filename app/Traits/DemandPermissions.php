<?php

namespace App\Traits;

use App\Enums\PermissionsActions;

trait DemandPermissions
{
    protected static function getModelName(): string {
        return strtolower(class_basename(static::class));
    }

    public static function canViewPermissions(): string {
        $permissions_array = self::canViewArray();
        return implode(",", $permissions_array);
    }

    public static function canViewArray(): array {
        $model = self::getModelName();
        return [
            $model.".".PermissionsActions::VIEW->value,
        ];
    }

    public static function canViewOthersPermissions(): string {
        $permissions_array = self::canViewOthersArray();
        return implode(",", $permissions_array);
    }

    public static function canViewOthersArray(): array {
        $model = self::getModelName();
        return [
            $model.".".PermissionsActions::VIEW_OTHERS->value,
        ];
    }

    public static function canManagePermissions(): string {
        $permissions_array = self::canManageArray();
        return implode(",", $permissions_array);
    }

    public static function canManageArray(): array
    {
        $model = self::getModelName();
        return [
            $model.".".PermissionsActions::CREATE->value,
            $model.".".PermissionsActions::UPDATE->value,
            $model.".".PermissionsActions::UPDATE_OTHERS->value,
            $model.".".PermissionsActions::VIEW->value,
            $model.".".PermissionsActions::VIEW_OTHERS->value,
            $model.".".PermissionsActions::DELETE_OTHERS->value,
        ];
    }

    public static function canManageOwnPermissions(): string {
        $permissions_array = self::canManageOwnArray();
        return implode(",", $permissions_array);
    }

    public static function canManageOwnArray(): array
    {
        $model = self::getModelName();
        return [
            $model.".".PermissionsActions::CREATE->value,
            $model.".".PermissionsActions::DELETE->value,
            $model.".".PermissionsActions::UPDATE->value,
            $model.".".PermissionsActions::VIEW->value,
        ];
    }

    public function hasPermissionTo(string $permission): bool {
        $parts = explode('.', $permission,2);

        if (count($parts) !== 2) {
            return false;
        }
        [$resource, $action] = $parts;

        return in_array("*.*", $this->permissions) ||
            in_array("*.$action", $this->permissions) ||
            in_array("$resource.*", $this->permissions) ||
            in_array("$resource.$action", $this->permissions);
    }
}
