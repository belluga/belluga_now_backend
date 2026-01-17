<?php

declare(strict_types=1);

namespace App\Application\Initialization\Actions;

use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantRoleTemplate;

class CreateTenantAdminTemplateAction
{
    public function execute(Tenant $tenant): TenantRoleTemplate
    {
        $tenant->makeCurrent();

        $template = $tenant->roleTemplates()
            ->where('name', 'Admin')
            ->first();

        if (! $template) {
            $template = $tenant->roleTemplates()->create([
                'name' => 'Admin',
                'description' => 'Administrador',
                'permissions' => ['*'],
            ]);
        }

        $tenant->forgetCurrent();

        return $template;
    }
}
