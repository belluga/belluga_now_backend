<?php

namespace Tests\Traits;

use App\Models\Landlord\TenantRoleTemplate;

trait SeedsTenantSupportRoles
{
    protected function ensureTenantSupportRoles(): void
    {
        $tenant = $this->ensureCanonicalTenantExists($this->tenant);

        $defaults = [
            'role_roles_manager' => [
                'name' => 'Support Roles Manager',
                'permissions' => [
                    'profile:view',
                    'profile:update',
                    'landlord-user:view',
                    'landlord-user:create',
                    'landlord-user:delete',
                    'landlord-user:update',
                ],
            ],
            'role_users_manager' => [
                'name' => 'Support Users Manager',
                'permissions' => [
                    'profile:view',
                    'profile:update',
                    'landlord-user:view',
                    'landlord-user:create',
                    'landlord-user:delete',
                    'landlord-user:update',
                    'tenants:view',
                    'tenants:create',
                    'tenants:delete',
                    'tenants:update',
                    'tenants-roles:view',
                    'tenants-roles:create',
                    'tenants-roles:delete',
                    'tenants-roles:update',
                ],
            ],
            'role_visitor' => [
                'name' => 'Support Visitor',
                'permissions' => [
                    'profile:view',
                    'profile:update',
                ],
            ],
            'role_disposable' => [
                'name' => 'Support Disposable',
                'permissions' => [
                    'profile:view',
                    'profile:update',
                ],
            ],
        ];

        foreach ($defaults as $property => $definition) {
            /** @var TenantRoleTemplate|null $role */
            $role = $tenant->roleTemplates()
                ->withTrashed()
                ->where('name', $definition['name'])
                ->first();

            if (! $role instanceof TenantRoleTemplate) {
                $role = $tenant->roleTemplates()->create([
                    'name' => $definition['name'],
                    'permissions' => $definition['permissions'],
                ]);
            } else {
                if ($role->trashed()) {
                    $role->restore();
                }

                $role->permissions = $definition['permissions'];
                $role->save();
            }

            $this->tenant->{$property}->name = $role->name;
            $this->tenant->{$property}->id = (string) $role->_id;
        }
    }
}
