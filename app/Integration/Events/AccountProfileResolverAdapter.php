<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Application\AccountProfiles\AccountProfileGalleryService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Contracts\EventProfileResolverContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountProfileResolverAdapter implements EventProfileResolverContract
{
    public function __construct(
        private readonly AccountProfileRegistryService $profileRegistryService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
        private readonly AccountProfileMediaService $accountProfileMediaService,
        private readonly AccountProfileGalleryService $galleryService,
    ) {}

    public function resolvePhysicalHostByProfileId(string $profileId): array
    {
        $resolved = $this->resolvePhysicalHostsByProfileIds([$profileId]);

        if (! isset($resolved[$profileId])) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Physical host account profile not found.'],
            ]);
        }

        return $resolved[$profileId];
    }

    public function resolvePhysicalHostsByProfileIds(array $profileIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $profileId): string => trim((string) $profileId),
            $profileIds
        ), static fn (string $profileId): bool => $profileId !== '')));

        if ($ids === []) {
            return [];
        }

        $eligibleTypes = $this->typeSetProvider->queryablePoiEnabledTypes();
        $profiles = AccountProfile::query()
            ->whereIn('_id', $ids)
            ->whereIn('profile_type', $eligibleTypes)
            ->get()
            ->keyBy(static fn (AccountProfile $profile): string => (string) $profile->_id);

        $missing = array_diff($ids, array_keys($profiles->all()));
        if ($missing !== []) {
            $this->throwPhysicalHostEligibilityError($ids, $missing);
        }

        $resolved = [];
        foreach ($ids as $profileId) {
            /** @var AccountProfile $profile */
            $profile = $profiles[$profileId];
            $resolved[$profileId] = $this->formatPhysicalHostProfile($profile);
        }

        return $resolved;
    }

    /**
     * @return array{
     *   venue: array<string, mixed>,
     *   location: array<string, mixed>
     * }
     */
    private function formatPhysicalHostProfile(AccountProfile $profile): array
    {
        $profileType = trim((string) ($profile->profile_type ?? ''));
        if (! $this->isProfileTypeQueryable($profileType)) {
            throw ValidationException::withMessages([
                'place_ref.id' => ['Physical host account profile type is not queryable.'],
            ]);
        }
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

        $slug = $this->normalizeSlug($profile->slug ?? null);
        $supportsPublicNavigation = $this->typeSetProvider->isPublicCatalog($profileType);
        $canOpenPublicDetail = $slug !== null && $supportsPublicNavigation;
        $baseUrl = request()->getSchemeAndHttpHost();
        $avatarUrl = $this->accountProfileMediaService->normalizePublicUrl(
            $baseUrl,
            $profile,
            'avatar',
            $this->normalizeCandidateMediaInput($profile->avatar_url ?? null),
        );
        $coverUrl = $this->accountProfileMediaService->normalizePublicUrl(
            $baseUrl,
            $profile,
            'cover',
            $this->normalizeCandidateMediaInput($profile->cover_url ?? null),
        );

        return [
            'venue' => [
                'id' => (string) $profile->_id,
                'display_name' => $profile->display_name,
                'slug' => $slug,
                'can_open_public_detail' => $canOpenPublicDetail,
                'public_detail_path' => $canOpenPublicDetail
                    ? '/parceiro/'.$slug
                    : null,
                'profile_type' => (string) ($profile->profile_type ?? ''),
                'supports_public_navigation' => $supportsPublicNavigation,
                'tagline' => null,
                'hero_image_url' => $coverUrl,
                'logo_url' => $avatarUrl,
                'avatar_url' => $avatarUrl,
                'cover_url' => $coverUrl,
                'bio' => $profile->bio,
                'taxonomy_terms' => $this->taxonomyTermSummaryResolver->resolve(
                    is_array($profile->taxonomy_terms ?? null) ? $profile->taxonomy_terms : []
                ),
                'gallery_groups' => $this->galleryService->formatForRead($profile, $baseUrl),
            ],
            'location' => $location,
        ];
    }

    public function resolveEventPartyProfilesByIds(array $profileIds): array
    {
        $requestedIds = $this->normalizeProfileIds($profileIds);
        if ($requestedIds === []) {
            return [];
        }

        $profilesById = $this->resolveExistingEventPartyProfilesByIds($requestedIds);
        $missing = array_diff($requestedIds, array_keys($profilesById));
        if ($missing !== []) {
            $this->throwEventPartyEligibilityError($requestedIds, $missing);
        }

        $resolved = [];
        foreach ($requestedIds as $profileId) {
            $resolved[] = $profilesById[$profileId];
        }

        return $resolved;
    }

    public function resolveExistingEventPartyProfilesByIds(array $profileIds): array
    {
        $requestedIds = $this->normalizeProfileIds($profileIds);
        if ($requestedIds === []) {
            return [];
        }

        $eligibleTypes = array_values(array_filter(
            $this->resolveQueryableProfileTypes(),
            static fn (string $type): bool => $type !== 'venue'
        ));
        $profiles = AccountProfile::query()
            ->whereIn('_id', $requestedIds)
            ->whereIn('profile_type', $eligibleTypes)
            ->get();

        $resolved = [];
        foreach ($profiles as $profile) {
            if (! $profile instanceof AccountProfile) {
                continue;
            }

            $resolved[(string) $profile->_id] = $this->formatEventPartyProfile($profile);
        }

        return $resolved;
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

    public function resolveAccountIdsForProfileIds(array $profileIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $profileId): string => trim((string) $profileId),
            $profileIds
        ), static fn (string $profileId): bool => $profileId !== '')));

        if ($ids === []) {
            return [];
        }

        return AccountProfile::query()
            ->whereIn('_id', $ids)
            ->get(['account_id'])
            ->map(static fn (AccountProfile $profile): string => trim((string) ($profile->account_id ?? '')))
            ->filter(static fn (string $accountId): bool => $accountId !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function resolveProfileTypePluralLabelsByTypes(array $types): array
    {
        $normalizedTypes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $type): string => trim((string) $type),
            $types
        ), static fn (string $type): bool => $type !== '')));

        if ($normalizedTypes === []) {
            return [];
        }

        $labels = [];
        foreach (TenantProfileType::query()->whereIn('type', $normalizedTypes)->get() as $profileType) {
            $type = trim((string) ($profileType->type ?? ''));
            if ($type === '') {
                continue;
            }

            $rawLabels = is_array($profileType->labels ?? null) ? $profileType->labels : [];
            $plural = trim((string) ($rawLabels['plural'] ?? ''));
            $label = trim((string) ($profileType->label ?? ''));
            $labels[$type] = $plural !== ''
                ? $plural
                : ($label !== '' ? Str::plural($label) : Str::headline(Str::plural($type)));
        }

        return $labels;
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
        int $perPage = 15,
        ?string $accountId = null,
        ?string $baseUrl = null,
    ): LengthAwarePaginator {
        $normalizedPage = max(1, $page);
        $normalizedPerPage = max(1, min($perPage, 50));
        $normalizedSearch = trim((string) ($search ?? ''));
        $likePattern = $normalizedSearch === ''
            ? null
            : '%'.addcslashes($normalizedSearch, '%_\\').'%';

        $query = match ($candidateType) {
            'related_account_profile' => $this->queryRelatedAccountProfileCandidates($likePattern, $accountId),
            'physical_host' => $this->queryPhysicalHostCandidates($likePattern, $accountId),
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
                ->map(fn (AccountProfile $profile): array => $this->mapCandidate($profile, $baseUrl))
                ->values()
        );

        return $paginator;
    }

    private function queryPhysicalHostCandidates(
        ?string $likePattern,
        ?string $accountId
    ): Builder {
        $profileTypes = $this->typeSetProvider->queryablePubliclyNavigablePoiEnabledTypes();
        if ($profileTypes === []) {
            return AccountProfile::query()->whereRaw(['_id' => ['$exists' => false]]);
        }

        $query = AccountProfile::query()
            ->whereIn('profile_type', $profileTypes)
            ->whereNotNull('location.coordinates.0')
            ->whereNotNull('location.coordinates.1');

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

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
    private function queryRelatedAccountProfileCandidates(?string $likePattern, ?string $accountId): Builder
    {
        $allowedTypes = array_values(array_filter(
            $this->resolveQueryableProfileTypes(),
            static fn (string $type): bool => $type !== 'venue'
        ));
        if ($allowedTypes === []) {
            return AccountProfile::query()->whereRaw(['_id' => ['$exists' => false]]);
        }

        $query = AccountProfile::query()
            ->whereIn('profile_type', $allowedTypes);

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        if ($likePattern !== null) {
            $query->where(static function ($builder) use ($likePattern): void {
                $builder->where('display_name', 'like', $likePattern)
                    ->orWhere('slug', 'like', $likePattern);
            });
        }

        return $query;
    }

    public function isProfileTypeQueryable(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->resolveQueryableProfileTypes(), true);
    }

    public function isProfileTypePubliclyNavigable(string $profileType): bool
    {
        $normalized = trim($profileType);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->resolvePubliclyNavigableProfileTypes(), true);
    }

    /**
     * @return array<int, string>
     */
    private function resolveQueryableProfileTypes(): array
    {
        return $this->typeSetProvider->queryableTypes();
    }

    /**
     * @return array<int, string>
     */
    private function resolvePubliclyNavigableProfileTypes(): array
    {
        return $this->typeSetProvider->publiclyNavigableTypes();
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, string>
     */
    private function normalizeProfileIds(array $profileIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $profileId): string => trim((string) $profileId),
            $profileIds
        ), static fn (string $profileId): bool => $profileId !== '')));
    }

    /**
     * @param  array<int, string>  $requestedIds
     * @param  array<int, string>  $missingIds
     */
    private function throwPhysicalHostEligibilityError(array $requestedIds, array $missingIds): never
    {
        $profilesById = AccountProfile::query()
            ->whereIn('_id', array_values($requestedIds))
            ->get(['_id', 'profile_type'])
            ->keyBy(static fn (AccountProfile $profile): string => (string) $profile->_id);

        foreach ($missingIds as $profileId) {
            /** @var AccountProfile|null $profile */
            $profile = $profilesById->get($profileId);
            if (! $profile instanceof AccountProfile) {
                throw ValidationException::withMessages([
                    'place_ref.id' => ['Physical host account profile not found.'],
                ]);
            }

            $profileType = trim((string) ($profile->profile_type ?? ''));
            if (! $this->isProfileTypeQueryable($profileType)) {
                throw ValidationException::withMessages([
                    'place_ref.id' => ['Physical host account profile type is not queryable.'],
                ]);
            }

            if (! $this->profileRegistryService->isPoiEnabled($profileType)) {
                throw ValidationException::withMessages([
                    'place_ref.id' => ['Physical host account profile must have POI capability enabled.'],
                ]);
            }
        }

        throw ValidationException::withMessages([
            'place_ref.id' => ['Physical host account profile not found.'],
        ]);
    }

    /**
     * @param  array<int, string>  $requestedIds
     * @param  array<int, string>  $missingIds
     */
    private function throwEventPartyEligibilityError(array $requestedIds, array $missingIds): never
    {
        $profilesById = AccountProfile::query()
            ->whereIn('_id', array_values($requestedIds))
            ->get(['_id', 'profile_type'])
            ->keyBy(static fn (AccountProfile $profile): string => (string) $profile->_id);

        foreach ($missingIds as $profileId) {
            /** @var AccountProfile|null $profile */
            $profile = $profilesById->get($profileId);
            if (! $profile instanceof AccountProfile) {
                throw ValidationException::withMessages([
                    'event_parties' => ['Some event parties were not found.'],
                ]);
            }

            $profileType = trim((string) ($profile->profile_type ?? ''));
            if (! $this->isProfileTypeQueryable($profileType) || $profileType === 'venue') {
                throw ValidationException::withMessages([
                    'event_parties' => ['Related account profile type is not selectable.'],
                ]);
            }
        }

        throw ValidationException::withMessages([
            'event_parties' => ['Some event parties were not found.'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEventPartyProfile(AccountProfile $profile): array
    {
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
            'slug' => $this->normalizeSlug($profile->slug ?? null),
            'profile_type' => (string) ($profile->profile_type ?? ''),
            'avatar_url' => $profile->avatar_url ?? null,
            'cover_url' => $profile->cover_url ?? null,
            'highlight' => false,
            'genres' => array_values(array_filter($genres, static fn ($item): bool => $item !== '')),
            'taxonomy_terms' => $this->taxonomyTermSummaryResolver->resolve(
                is_array($profile->taxonomy_terms ?? null) ? $profile->taxonomy_terms : []
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCandidate(AccountProfile $profile, ?string $baseUrl = null): array
    {
        $normalizedBaseUrl = is_string($baseUrl) ? trim($baseUrl) : '';

        return [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => (string) $profile->profile_type,
            'display_name' => (string) ($profile->display_name ?? ''),
            'slug' => $this->normalizeSlug($profile->slug ?? null),
            'avatar_url' => $normalizedBaseUrl !== ''
                ? $this->accountProfileMediaService->normalizePublicUrl(
                    $normalizedBaseUrl,
                    $profile,
                    'avatar',
                    $this->normalizeCandidateMediaInput($profile->avatar_url ?? null),
                )
                : (is_scalar($profile->avatar_url ?? null)
                    ? trim((string) $profile->avatar_url)
                    : null),
            'cover_url' => $normalizedBaseUrl !== ''
                ? $this->accountProfileMediaService->normalizePublicUrl(
                    $normalizedBaseUrl,
                    $profile,
                    'cover',
                    $this->normalizeCandidateMediaInput($profile->cover_url ?? null),
                )
                : (is_scalar($profile->cover_url ?? null)
                    ? trim((string) $profile->cover_url)
                    : null),
        ];
    }

    private function normalizeCandidateMediaInput(mixed $rawUrl): ?string
    {
        if (! is_scalar($rawUrl)) {
            return null;
        }

        $normalized = trim((string) $rawUrl);
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, 'account-profiles/')
            || str_starts_with($normalized, 'api/v1/media/account-profiles/')) {
            return '/'.$normalized;
        }

        return $normalized;
    }

    private function normalizeSlug(mixed $slug): ?string
    {
        $normalized = trim((string) $slug);

        return $normalized !== '' ? $normalized : null;
    }
}
