<?php

declare(strict_types=1);

namespace App\Application\Organizations;

use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Tenants\Organization;
use Illuminate\Pagination\LengthAwarePaginator;

class OrganizationQueryService extends AbstractQueryService
{
    public function paginate(array $queryParams, bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        $query = Organization::query();

        return $this->buildPaginator($query, $queryParams, $includeArchived, $perPage)
            ->through(static function (Organization $organization): array {
                return [
                    'id' => (string) $organization->_id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'description' => $organization->description,
                    'created_at' => $organization->created_at?->toJSON(),
                    'updated_at' => $organization->updated_at?->toJSON(),
                    'deleted_at' => $organization->deleted_at?->toJSON(),
                ];
            });
    }

    public function findByIdOrFail(string $organizationId, bool $onlyTrashed = false): Organization
    {
        $query = $onlyTrashed ? Organization::onlyTrashed() : Organization::query();

        return $query->where('_id', $organizationId)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Organization $organization): array
    {
        return [
            'id' => (string) $organization->_id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'description' => $organization->description,
            'created_at' => $organization->created_at?->toJSON(),
            'updated_at' => $organization->updated_at?->toJSON(),
            'deleted_at' => $organization->deleted_at?->toJSON(),
        ];
    }

    protected function baseSearchableFields(): array
    {
        return (new Organization())->getFillable();
    }

    protected function stringFields(): array
    {
        return ['name', 'slug', 'description'];
    }

    protected function arrayFields(): array
    {
        return [];
    }

    protected function dateFields(): array
    {
        return ['created_at', 'updated_at', 'deleted_at'];
    }
}
