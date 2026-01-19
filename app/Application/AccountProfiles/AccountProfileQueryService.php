<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Shared\Query\AbstractQueryService;
use App\Models\Tenants\AccountProfile;
use Illuminate\Pagination\LengthAwarePaginator;

class AccountProfileQueryService extends AbstractQueryService
{
    public function paginate(array $queryParams, bool $includeArchived, int $perPage = 15): LengthAwarePaginator
    {
        $query = AccountProfile::query();

        return $this->buildPaginator($query, $queryParams, $includeArchived, $perPage)
            ->through(static function (AccountProfile $profile): array {
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
