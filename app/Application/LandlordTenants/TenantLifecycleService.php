<?php

declare(strict_types=1);

namespace App\Application\LandlordTenants;

use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantRoleTemplate;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Laravel\Eloquent\Builder as MongoBuilder;

class TenantLifecycleService
{
    public function paginate(LandlordUser $operator, bool $includeArchived, int $perPage): LengthAwarePaginator
    {
        return $this->queryAccessibleTenants($operator, includeArchived: $includeArchived, withTrashed: false)
            ->with('domains')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->through(static fn (Tenant $tenant): array => [
                'id' => (string) $tenant->_id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'description' => $tenant->description,
                'subdomain' => $tenant->subdomain,
                'main_domain' => $tenant->getMainDomain(),
                'domains' => $tenant->resolvedDomains(),
                'database' => $tenant->database,
                'app_domains' => $tenant->app_domains,
                'created_at' => $tenant->created_at?->toJSON(),
                'updated_at' => $tenant->updated_at?->toJSON(),
                'deleted_at' => $tenant->deleted_at?->toJSON(),
            ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{tenant: Tenant, role: TenantRoleTemplate}
     */
    public function create(array $payload, LandlordUser $operator): array
    {
        try {
            return DB::connection('landlord')->transaction(function () use ($payload, $operator): array {
                $tenant = Tenant::create($payload);

                $role = $tenant->roleTemplates()->create([
                    'name' => 'Admin',
                    'description' => 'Administrador',
                    'permissions' => ['*'],
                ]);

                $operator->tenantRoles()->create([
                    ...$role->attributesToArray(),
                    'tenant_id' => $tenant->id,
                ]);

                return [
                    'tenant' => $tenant->fresh(),
                    'role' => $role->fresh(),
                ];
            });
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'tenant' => ['Tenant already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'tenant' => ['An error occurred while trying to create the tenant.'],
            ]);
        }
    }

    public function findAccessibleBySlug(LandlordUser $operator, string $slug): Tenant
    {
        return $this->queryAccessibleTenants($operator, includeArchived: false, withTrashed: true)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Tenant $tenant, array $attributes): Tenant
    {
        $tenant->fill($attributes);
        $tenant->save();

        return $tenant->fresh();
    }

    public function restore(LandlordUser $operator, string $slug): Tenant
    {
        $tenant = $this->queryAccessibleTenants($operator, includeArchived: true, withTrashed: true)
            ->onlyTrashed()
            ->where('slug', $slug)
            ->firstOrFail();

        $tenant->restore();

        return $tenant->fresh();
    }

    public function delete(LandlordUser $operator, string $slug): void
    {
        $tenant = $this->queryAccessibleTenants($operator, includeArchived: false, withTrashed: true)
            ->where('slug', $slug)
            ->firstOrFail();

        $tenant->delete();
    }

    public function forceDelete(LandlordUser $operator, string $slug): void
    {
        $tenant = $this->queryAccessibleTenants($operator, includeArchived: true, withTrashed: true)
            ->onlyTrashed()
            ->where('slug', $slug)
            ->firstOrFail();

        DB::connection('landlord')->transaction(static function () use ($tenant): void {
            $tenant->domains()->withTrashed()->get()->each->forceDelete();
            $tenant->roleTemplates()->withTrashed()->get()->each->forceDelete();
            $tenant->forceDelete();
        });
    }

    private function queryAccessibleTenants(
        LandlordUser $operator,
        bool $includeArchived,
        bool $withTrashed
    ): MongoBuilder
    {
        $accessIds = array_map(
            static fn ($id): ObjectId => new ObjectId((string) $id),
            $operator->getAccessToIds()
        );

        $builder = Tenant::query()
            ->whereRaw(['_id' => ['$in' => $accessIds ?: [new ObjectId()]]]);

        if ($withTrashed) {
            $builder->withTrashed();
        }

        if ($includeArchived) {
            $builder->onlyTrashed();
        }

        return $builder;
    }
}
