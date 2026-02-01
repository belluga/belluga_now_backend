<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MongoDB\BSON\ObjectId;

class AccountProfileQueryService extends AbstractQueryService
{
    public function paginate(array $queryParams, bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        $query = AccountProfile::query();

        return $this->buildPaginator($query, $queryParams, $includeArchived, $perPage)
            ->through(function (AccountProfile $profile): array {
                return [
                    'id' => (string) $profile->_id,
                    'account_id' => (string) $profile->account_id,
                    'profile_type' => $profile->profile_type,
                    'display_name' => $profile->display_name,
                    'slug' => $profile->slug,
                    'avatar_url' => $profile->avatar_url,
                    'cover_url' => $profile->cover_url,
                    'bio' => $profile->bio,
                    'taxonomy_terms' => $profile->taxonomy_terms ?? [],
                    'location' => $this->formatLocation($profile->location),
                    'created_at' => $profile->created_at?->toJSON(),
                    'updated_at' => $profile->updated_at?->toJSON(),
                    'deleted_at' => $profile->deleted_at?->toJSON(),
                ];
            });
    }

    public function publicPaginate(array $queryParams, int $perPage = 15): LengthAwarePaginator
    {
        $allowedTypes = TenantProfileType::query()->pluck('type')->all();
        $query = $queryParams;
        if (! empty($allowedTypes)) {
            $existingFilters = (array) ($query['filter'] ?? []);
            $requested = $existingFilters['profile_type'] ?? null;
            if ($requested !== null) {
                $requestedList = is_array($requested) ? $requested : [$requested];
                $effectiveTypes = array_values(array_intersect($allowedTypes, $requestedList));
            } else {
                $effectiveTypes = $allowedTypes;
            }

            $query['filter'] = array_merge(
                $existingFilters,
                ['profile_type' => $effectiveTypes]
            );
        }

        return $this->paginate($query, false, $perPage);
    }

    public function findOrFail(string $profileId, bool $onlyTrashed = false): AccountProfile
    {
        $query = $onlyTrashed ? AccountProfile::onlyTrashed() : AccountProfile::query();
        $profile = $query->find($profileId);

        if (! $profile) {
            try {
                $profile = $query->where('_id', new ObjectId($profileId))->first();
            } catch (\Throwable) {
                $profile = null;
            }
        }

        if (! $profile) {
            throw (new ModelNotFoundException())->setModel(AccountProfile::class, [$profileId]);
        }

        return $profile;
    }

    /**
     * @param mixed $location
     * @return array<string, float>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
        ];
    }

    protected function baseSearchableFields(): array
    {
        return (new AccountProfile())->getFillable();
    }

    protected function stringFields(): array
    {
        return ['profile_type', 'display_name', 'slug'];
    }

    protected function arrayFields(): array
    {
        return [];
    }

    protected function dateFields(): array
    {
        return ['created_at', 'updated_at', 'deleted_at'];
    }

    protected function extraSearchableFields(): array
    {
        return ['account_id'];
    }
}
