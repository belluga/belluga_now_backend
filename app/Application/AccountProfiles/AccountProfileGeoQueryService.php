<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Tenants\AccountProfile;
use Illuminate\Support\Arr;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Eloquent\Collection;

class AccountProfileGeoQueryService
{
    /**
     * @param array<string, mixed> $queryParams
     * @return array<int, array<string, mixed>>
     */
    public function search(array $queryParams): array
    {
        $originLat = Arr::get($queryParams, 'origin_lat');
        $originLng = Arr::get($queryParams, 'origin_lng');
        $maxDistance = Arr::get($queryParams, 'max_distance_meters');
        $profileTypes = Arr::get($queryParams, 'profile_type');

        $pipeline = [];

        if ($originLat !== null && $originLng !== null) {
            $geoNear = [
                'near' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $originLng, (float) $originLat],
                ],
                'distanceField' => 'distance_meters',
                'spherical' => true,
                'query' => [
                    'location' => ['$ne' => null],
                    'is_active' => true,
                    'deleted_at' => null,
                ],
            ];

            if ($maxDistance !== null) {
                $geoNear['maxDistance'] = (float) $maxDistance;
            }

            $pipeline[] = ['$geoNear' => $geoNear];
        }

        if ($originLat === null || $originLng === null) {
            $pipeline[] = [
                '$match' => [
                    'is_active' => true,
                    'deleted_at' => null,
                ],
            ];
        }

        if ($profileTypes !== null) {
            $types = is_array($profileTypes) ? $profileTypes : [$profileTypes];
            $pipeline[] = [
                '$match' => [
                    'profile_type' => ['$in' => array_values($types)],
                ],
            ];
        }

        $pipeline[] = [
            '$sort' => [
                'distance_meters' => 1,
                'created_at' => -1,
            ],
        ];

        $pipeline[] = [
            '$limit' => (int) (Arr::get($queryParams, 'limit') ?? 50),
        ];

        /** @var Collection<int, AccountProfile> $profiles */
        $profiles = AccountProfile::raw(function ($collection) use ($pipeline) {
            return $collection->aggregate($pipeline);
        });

        return $profiles->map(function ($profile): array {
            $payload = [
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
                'created_at' => $this->formatDate($profile->created_at ?? null),
                'updated_at' => $this->formatDate($profile->updated_at ?? null),
            ];

            if (property_exists($profile, 'distance_meters')) {
                $payload['distance_meters'] = (float) $profile->distance_meters;
            }

            return $payload;
        })->all();
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

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return null;
    }
}
