<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Models\Tenants\AccountProfile;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class AccountProfileResolverAdapter implements EventProfileResolverContract
{
    public function __construct(
        private readonly AccountProfileRegistryService $profileRegistryService,
    ) {}

    public function resolvePhysicalHostByProfileId(string $profileId): array
    {
        $profile = AccountProfile::query()->where('_id', $profileId)->first();

        if (! $profile) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Physical host account profile not found.'],
            ]);
        }

        $profileType = trim((string) ($profile->profile_type ?? ''));
        if (! $this->profileRegistryService->isPoiEnabled($profileType)) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Physical host account profile must have POI capability enabled.'],
            ]);
        }

        $location = $profile->location ?? null;
        if (! is_array($location) || ! isset($location['type'], $location['coordinates'])) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Physical host account profile must include a location.'],
            ]);
        }
        if (! is_array($location['coordinates']) || count($location['coordinates']) < 2) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Physical host account profile must include valid coordinates.'],
            ]);
        }

        return [
            'venue' => [
                'id' => (string) $profile->_id,
                'display_name' => $profile->display_name,
                'tagline' => null,
                'hero_image_url' => $profile->cover_url ?? null,
                'logo_url' => $profile->avatar_url ?? null,
                'taxonomy_terms' => $profile->taxonomy_terms ?? [],
            ],
            'location' => $location,
        ];
    }

    public function resolveArtistsByProfileIds(array $artistProfileIds): array
    {
        if ($artistProfileIds === []) {
            return [];
        }

        $profiles = AccountProfile::query()
            ->whereIn('_id', array_values($artistProfileIds))
            ->get();

        $resolvedIds = $profiles->pluck('_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $missing = array_diff($artistProfileIds, $resolvedIds);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'artist_ids' => ['Some artists were not found.'],
            ]);
        }

        $invalid = $profiles->filter(
            static fn (AccountProfile $profile): bool => $profile->profile_type !== 'artist'
        );
        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'artist_ids' => ['All artists must be account profiles of type artist.'],
            ]);
        }

        return $profiles->map(static function (AccountProfile $profile): array {
            $taxonomy = $profile->taxonomy_terms ?? [];
            $genres = [];

            if (is_array($taxonomy)) {
                foreach ($taxonomy as $term) {
                    if (! is_array($term)) {
                        continue;
                    }

                    $type = $term['type'] ?? '';
                    if (in_array($type, ['music_genre', 'genre'], true)) {
                        $genres[] = (string) ($term['value'] ?? '');
                    }
                }
            }

            return [
                'id' => (string) $profile->_id,
                'display_name' => $profile->display_name,
                'avatar_url' => $profile->avatar_url ?? null,
                'highlight' => false,
                'genres' => array_values(array_filter($genres, static fn ($item): bool => $item !== '')),
                'taxonomy_terms' => $profile->taxonomy_terms ?? [],
            ];
        })->all();
    }

    public function listProfileIdsForAccount(string $accountId): array
    {
        return AccountProfile::query()
            ->where('account_id', $accountId)
            ->get()
            ->map(static fn (AccountProfile $profile): string => (string) $profile->_id)
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    public function accountOwnsProfile(string $accountId, string $profileId): bool
    {
        return AccountProfile::query()
            ->where('_id', $profileId)
            ->where('account_id', $accountId)
            ->exists();
    }

    public function paginateAccountProfileCandidates(
        string $candidateType,
        ?string $search = null,
        int $page = 1,
        int $perPage = 15
    ): LengthAwarePaginator
    {
        $normalizedPage = max(1, $page);
        $normalizedPerPage = max(1, min($perPage, 50));
        $normalizedSearch = trim((string) ($search ?? ''));
        $likePattern = $normalizedSearch === ''
            ? null
            : '%'.addcslashes($normalizedSearch, '%_\\').'%';

        $query = match ($candidateType) {
            'artist' => $this->queryCandidatesByType('artist', $likePattern),
            'physical_host' => $this->queryPhysicalHostCandidates(
                $this->resolvePoiEnabledProfileTypes(),
                $likePattern
            ),
            default => throw ValidationException::withMessages([
                'type' => ['Unsupported account profile candidate type.'],
            ]),
        };

        $paginator = $query
            ->orderBy('display_name')
            ->orderBy('_id')
            ->paginate($normalizedPerPage, ['*'], 'page', $normalizedPage);

        $paginator->setCollection(
            $paginator->getCollection()
                ->filter(static fn ($profile): bool => $profile instanceof AccountProfile)
                ->map(fn (AccountProfile $profile): array => $this->mapCandidate($profile))
                ->values()
        );

        return $paginator;
    }

    /**
     * @return array<int, string>
     */
    private function resolvePoiEnabledProfileTypes(): array
    {
        return collect($this->profileRegistryService->registry())
            ->filter(static function (array $definition): bool {
                $capabilities = $definition['capabilities'] ?? [];

                return ($capabilities['is_poi_enabled'] ?? false) === true;
            })
            ->map(static fn (array $definition): string => trim((string) ($definition['type'] ?? '')))
            ->filter(static fn (string $type): bool => $type !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $profileTypes
     */
    private function queryPhysicalHostCandidates(array $profileTypes, ?string $likePattern): Builder
    {
        if ($profileTypes === []) {
            return AccountProfile::query()->whereRaw(['_id' => ['$exists' => false]]);
        }

        $query = AccountProfile::query()
            ->whereIn('profile_type', $profileTypes)
            ->whereNotNull('location.coordinates.0')
            ->whereNotNull('location.coordinates.1');

        if ($likePattern !== null) {
            $query->where(static function ($builder) use ($likePattern): void {
                $builder->where('display_name', 'like', $likePattern)
                    ->orWhere('slug', 'like', $likePattern);
            });
        }

        return $query;
    }

    /**
     * @return Builder<AccountProfile>
     */
    private function queryCandidatesByType(string $profileType, ?string $likePattern): Builder
    {
        $query = AccountProfile::query()
            ->where('profile_type', $profileType);

        if ($likePattern !== null) {
            $query->where(static function ($builder) use ($likePattern): void {
                $builder->where('display_name', 'like', $likePattern)
                    ->orWhere('slug', 'like', $likePattern);
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCandidate(AccountProfile $profile): array
    {
        return [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => (string) $profile->profile_type,
            'display_name' => (string) ($profile->display_name ?? ''),
            'slug' => $profile->slug ? (string) $profile->slug : null,
            'avatar_url' => $profile->avatar_url ?? null,
            'cover_url' => $profile->cover_url ?? null,
        ];
    }
}
