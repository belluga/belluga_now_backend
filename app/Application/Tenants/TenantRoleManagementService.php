<?php

declare(strict_types=1);

namespace App\Application\Tenants;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantRoleTemplate;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;

class TenantRoleManagementService
{
    public function paginate(Tenant $tenant, bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        return TenantRoleTemplate::query()
            ->where('tenant_id', $tenant->id)
            ->when(
                $includeArchived,
                static fn ($query) => $query->onlyTrashed()
            )
            ->paginate($perPage);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(Tenant $tenant, array $payload): TenantRoleTemplate
    {
        try {
            $role = $tenant->roleTemplates()->create($payload);

            return $role->fresh();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'role' => ['Role already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'role' => ['Something went wrong when trying to create the role.'],
            ]);
        }
    }

    public function find(Tenant $tenant, string $roleId, bool $withTrashed = false): TenantRoleTemplate
    {
        $query = TenantRoleTemplate::query()
            ->where('tenant_id', $tenant->id)
            ->where('_id', new ObjectId($roleId));

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->firstOrFail();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Tenant $tenant, string $roleId, array $attributes): TenantRoleTemplate
    {
        $role = $this->find($tenant, $roleId);

        $permissionsMutated = false;

        if (Arr::exists($attributes, 'permissions')) {
            $role->permissions = $this->mutatePermissions(
                $role->permissions ?? [],
                (array) $attributes['permissions']
            );
            unset($attributes['permissions']);
            $permissionsMutated = true;
        }

        if (! empty($attributes)) {
            $role->fill($attributes);
            $role->save();
        } elseif ($permissionsMutated) {
            $role->save();
        }

        return $role->fresh();
    }

    public function delete(Tenant $tenant, string $roleId, string $fallbackRoleId): void
    {
        $role = $this->find($tenant, $roleId);
        $fallback = $this->find($tenant, $fallbackRoleId);

        DB::connection('landlord')->transaction(static function () use ($role, $fallback): void {
            LandlordUser::query()
                ->where('role_id', $role->id)
                ->update(['role_id' => $fallback->id]);

            $role->delete();
        });
    }

    public function restore(Tenant $tenant, string $roleId): TenantRoleTemplate
    {
        $role = TenantRoleTemplate::onlyTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('_id', new ObjectId($roleId))
            ->firstOrFail();

        $role->restore();

        return $role->fresh();
    }

    public function forceDelete(Tenant $tenant, string $roleId): void
    {
        $role = TenantRoleTemplate::onlyTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('_id', new ObjectId($roleId))
            ->firstOrFail();

        DB::connection('landlord')->transaction(static function () use ($role): void {
            $role->forceDelete();
        });
    }

    /**
     * @param array<int, string> $current
     * @param array<string, mixed> $mutation
     * @return array<int, string>
     */
    private function mutatePermissions(array $current, array $mutation): array
    {
        if (array_key_exists('set', $mutation)) {
            return array_values(array_unique((array) $mutation['set']));
        }

        $permissions = $current;

        if (array_key_exists('add', $mutation)) {
            $permissions = array_merge($permissions, (array) $mutation['add']);
        }

        if (array_key_exists('remove', $mutation)) {
            $permissions = array_diff($permissions, (array) $mutation['remove']);
        }

        return array_values(array_unique($permissions));
    }
}
