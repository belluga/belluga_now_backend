<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Tenants\MenuDefaults;

trait InitializesDefaultValues
{
    private function initializeDefaults(): void
    {
        $this->initializeSchemas();
        $this->initializeMenuSettings();
    }

    private function initializeSchemas(): void
    {
        $this->fields_schema ??= $this->getModuleFieldsSchema();
        $this->permissions_schema ??= $this->getModulePermissionsSchema();
    }

    private function initializeMenuSettings(): void
    {
        $this->show_in_menu ??= MenuDefaults::SHOW_IN_MENU;
        $this->menu_position ??= MenuDefaults::POSITION;
        $this->menu_icon ??= MenuDefaults::ICON;
    }
}
