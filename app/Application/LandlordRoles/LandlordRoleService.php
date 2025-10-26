<?php

declare(strict_types=1);

namespace App\Application\LandlordRoles;

use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;

class LandlordRoleService
{
    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): LandlordRole
    {
        return DB::connection('landlord')->transaction(fn () => LandlordRole::create($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(LandlordRole $role, array $data): LandlordRole
    {
        return DB::connection('landlord')->transaction(function () use ($role, $data): LandlordRole {
            if (isset($data['permissions'])) {
                $role->permissions = $this->applyPermissionMutation($role->permissions ?? [], $data['permissions']);
                unset($data['permissions']);
            }

            $role->update($data);

            return $role->refresh();
        });
    }

    public function deleteWithReassignment(LandlordRole $role, string $backgroundRoleId): void
    {
        DB::connection('landlord')->transaction(function () use ($role, $backgroundRoleId): void {
            LandlordUser::where('role_id', (string) $role->_id)
                ->update(['role_id' => $backgroundRoleId]);

            $role->delete();
        });
    }

    public function forceDelete(LandlordRole $role): void
    {
        DB::connection('landlord')->transaction(
            fn () => $role->forceDelete()
        );
    }

    /**
     * @param array<int, string> $current
     * @param array<string, array<int, string>>|array<int, string> $mutation
     * @return array<int, string>
     */
    private function applyPermissionMutation(array $current, array $mutation): array
    {
        // Support simple replacement (legacy behaviour) when mutation is a flat array
        if ($this->isFlatArray($mutation)) {
            return array_values(array_unique($mutation));
        }

        if (isset($mutation['set'])) {
            return array_values(array_unique($mutation['set']));
        }

        if (isset($mutation['add'])) {
            $current = array_merge($current, $mutation['add']);
        }

        if (isset($mutation['remove'])) {
            $current = array_values(array_diff($current, $mutation['remove']));
        }

        return array_values(array_unique($current));
    }

    private function isFlatArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
