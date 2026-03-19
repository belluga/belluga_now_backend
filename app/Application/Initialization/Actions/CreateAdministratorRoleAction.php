<?php

declare(strict_types=1);

namespace App\Application\Initialization\Actions;

use App\Models\Landlord\LandlordRole;
use Illuminate\Support\Str;

class CreateAdministratorRoleAction
{
    /**
     * @param  array<string, mixed>  $roleData
     */
    public function execute(array $roleData): LandlordRole
    {
        $slug = Str::slug($roleData['name'] ?? '');
        $role = LandlordRole::query()
            ->where('name', $roleData['name'])
            ->when($slug !== '', fn ($query) => $query->orWhere('slug', $slug))
            ->first();

        if (! $role) {
            return LandlordRole::create($roleData);
        }

        $role->permissions = $roleData['permissions'] ?? $role->permissions;
        $role->save();

        return $role;
    }
}
