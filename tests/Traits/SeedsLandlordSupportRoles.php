<?php

namespace Tests\Traits;

use App\Models\Landlord\LandlordRole;

trait SeedsLandlordSupportRoles
{
    protected function ensureSupportRoles(): void
    {
        $defaults = [
            'role_tenants_manager' => [
                'name' => 'Support Tenants Manager',
                'permissions' => ['tenants:view', 'tenants:create', 'tenants:update'],
            ],
            'role_users_manager' => [
                'name' => 'Support Users Manager',
                'permissions' => ['landlord-users:view', 'landlord-users:create', 'landlord-users:update'],
            ],
            'role_visitor' => [
                'name' => 'Support Visitor',
                'permissions' => ['profile:view'],
            ],
            'role_disposable' => [
                'name' => 'Support Disposable',
                'permissions' => ['profile:view'],
            ],
        ];

        foreach ($defaults as $property => $definition) {
            $role = LandlordRole::firstOrCreate(
                ['name' => $definition['name']],
                ['permissions' => $definition['permissions']]
            );
            $role->permissions = $definition['permissions'];
            $role->save();

            $this->landlord->{$property}->name = $role->name;
            $this->landlord->{$property}->id = (string) $role->_id;
        }
    }
}
