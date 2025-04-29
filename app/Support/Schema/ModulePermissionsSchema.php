<?php

declare(strict_types=1);

namespace App\Support\Schema;

class ModulePermissionsSchema
{
    public function getDefaultSchema(): array
    {
        return [
            'create' => true,
            'read' => true,
            'update' => true,
            'delete' => true
        ];
    }
}
