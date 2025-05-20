<?php

namespace App\Traits;

use App\Enums\PermissionsActions;

trait HasAbilities
{
    protected static function getModelName(): string {
        return strtolower(class_basename(static::class));
    }

    public static function canView(): array {
        $model = self::getModelName();
        return [
            $model.".".PermissionsActions::VIEW->value,
        ];
    }

    public static function canViewOthers(): array {
        $model = self::getModelName();
        return [
            $model.".".PermissionsActions::VIEW_OTHERS->value,
        ];
    }

    public static function canManage(): array
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

    public static function canManageOwn(): array
    {
        $model = self::getModelName();
        return [
            $model.".".PermissionsActions::CREATE->value,
            $model.".".PermissionsActions::DELETE->value,
            $model.".".PermissionsActions::UPDATE->value,
            $model.".".PermissionsActions::VIEW->value,
        ];
    }
}
