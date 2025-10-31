<?php

declare(strict_types=1);

namespace App\Application\LandlordUsers;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use MongoDB\BSON\ObjectId;

class TenantUserRoleManager
{
    public function assign(string $userId, string $roleId, Tenant $tenant): void
    {
        $user = $this->findUser($userId);

        $role = $tenant->roleTemplates()
            ->where('_id', new ObjectId($roleId))
            ->firstOrFail();

        $user->tenantRoles()->create([
            ...$role->attributesToArray(),
            'tenant_id' => $tenant->id,
        ]);
    }

    public function revoke(string $userId, string $roleId, Tenant $tenant): void
    {
        $user = $this->findUser($userId);

        $role = $tenant->roleTemplates()
            ->where('_id', new ObjectId($roleId))
            ->firstOrFail();

        $roleToDelete = $user->tenantRoles()
            ->where('slug', $role->slug)
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($roleToDelete) {
            $roleToDelete->delete();
            $user->save();
        }
    }

    private function findUser(string $userId): LandlordUser
    {
        return LandlordUser::where('_id', new ObjectId($userId))->firstOrFail();
    }
}
