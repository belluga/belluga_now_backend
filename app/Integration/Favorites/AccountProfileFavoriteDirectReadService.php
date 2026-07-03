<?php

declare(strict_types=1);

namespace App\Integration\Favorites;

use App\Application\AccountProfiles\AccountProfileTypeSetProvider;
use App\Models\Tenants\AccountProfile;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Favorites\Contracts\AccountProfileFavoriteDirectReadContract;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class AccountProfileFavoriteDirectReadService implements AccountProfileFavoriteDirectReadContract
{
    private const int DEFAULT_PAGE_SIZE = 10;

    public function __construct(
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
    ) {}

    /**
     * @return array{items: array<int, array<string, mixed>>, has_more: bool}
     */
    public function listForOwner(
        string $ownerUserId,
        int $page,
        int $pageSize,
    ): array {
        $resolvedPage = max(1, $page);
        $resolvedPageSize = $pageSize > 0
            ? min($pageSize, self::DEFAULT_PAGE_SIZE)
            : self::DEFAULT_PAGE_SIZE;
        $skip = ($resolvedPage - 1) * $resolvedPageSize;

        $edges = FavoriteEdge::query()
            ->where('owner_user_id', $ownerUserId)
            ->where('registry_key', 'account_profile')
            ->where('target_type', 'account_profile')
            ->orderBy('favorited_at', 'desc')
            ->orderBy('_id')
            ->get(['_id', 'target_id', 'favorited_at']);

        if ($edges->isEmpty()) {
            return [
                'items' => [],
                'has_more' => false,
            ];
        }

        $profiles = $this->loadActiveProfiles($edges);
        if ($profiles === []) {
            return [
                'items' => [],
                'has_more' => false,
            ];
        }

        $occurrenceStates = $this->loadLiveAndNextOccurrenceStates(array_keys($profiles));
        $rows = [];

        foreach ($edges as $edge) {
            $targetId = trim((string) ($edge->target_id ?? ''));
            if ($targetId === '') {
                continue;
            }

            $profile = $profiles[$targetId] ?? null;
            if (! $profile instanceof AccountProfile) {
                continue;
            }

            $state = $occurrenceStates[$targetId] ?? [
                'live_now' => null,
                'next' => null,
                'last' => null,
            ];

            $rows[] = $this->buildRow(
                edge: $edge,
                profile: $profile,
                liveNowOccurrence: $state['live_now'] ?? null,
                nextOccurrence: $state['next'] ?? null,
                lastOccurrence: null,
            );
        }

        usort($rows, fn (array $left, array $right): int => $this->compareRows($left, $right));

        $pagedRows = array_slice($rows, $skip, $resolvedPageSize);
        $lastOccurrenceStates = $this->loadLastOccurrenceStates(array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['profile_id'] ?? ''),
            $pagedRows,
        ))));

        return [
            'items' => array_map(
                fn (array $row): array => $this->applyLastOccurrenceState(
                    $row['payload'],
                    $lastOccurrenceStates[(string) ($row['profile_id'] ?? '')] ?? null,
                ),
                $pagedRows,
            ),
            'has_more' => count($rows) > ($skip + $resolvedPageSize),
        ];
    }

    /**
     * @param  iterable<int, FavoriteEdge>  $edges
     * @return array<string, AccountProfile>
     */
    private function loadActiveProfiles(iterable $edges): array
    {
        $targetIds = [];
        foreach ($edges as $edge) {
            $targetId = trim((string) ($edge->target_id ?? ''));
            if ($targetId !== '') {
                $targetIds[$targetId] = $targetId;
            }
        }

        if ($targetIds === []) {
            return [];
        }

        $profiles = AccountProfile::withTrashed()
            ->whereIn('_id', array_values($targetIds))
            ->get([
                '_id',
                'slug',
                'display_name',
                'avatar_url',
                'cover_url',
                'profile_type',
                'is_active',
                'deleted_at',
            ]);

        $activeProfiles = [];
        foreach ($profiles as $profile) {
            if ($profile->trashed() || (bool) ($profile->is_active ?? true) === false) {
                continue;
            }

            $activeProfiles[(string) $profile->getAttribute('_id')] = $profile;
        }

        return $activeProfiles;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array{live_now:?EventOccurrence,next:?EventOccurrence,last:?EventOccurrence}>
     */
    private function loadLiveAndNextOccurrenceStates(array $profileIds): array
    {
        $normalizedProfileIds = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $profileIds,
        ), static fn (string $value): bool => $value !== '')));

        if ($normalizedProfileIds === []) {
            return [];
        }

        $profileIdCandidates = [];
        foreach ($normalizedProfileIds as $profileId) {
            foreach ($this->buildProfileIdCandidates($profileId) as $candidate) {
                $profileIdCandidates[] = $candidate;
            }
        }

        $now = Carbon::now();

        $occurrences = EventOccurrence::query()
            ->where('deleted_at', null)
            ->where('is_event_published', true)
            ->where(static function ($query) use ($now): void {
                $query->where('effective_ends_at', '>', $now)
                    ->orWhere('starts_at', '>=', $now);
            })
            ->where(static function ($query) use ($profileIdCandidates): void {
                $query->where(static function ($query) use ($profileIdCandidates): void {
                    $query->where('place_ref.type', 'account_profile')
                        ->where(static function ($query) use ($profileIdCandidates): void {
                            $query->whereIn('place_ref.id', $profileIdCandidates)
                                ->orWhereIn('place_ref._id', $profileIdCandidates);
                        });
                })->orWhereRaw([
                    'event_parties' => [
                        '$elemMatch' => [
                            'party_ref_id' => ['$in' => $profileIdCandidates],
                        ],
                    ],
                ]);
            })
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->get([
                '_id',
                'slug',
                'starts_at',
                'effective_ends_at',
                'ends_at',
                'place_ref',
                'event_parties',
            ]);

        $states = [];
        $favoriteProfileIdSet = array_fill_keys($normalizedProfileIds, true);

        foreach ($occurrences as $occurrence) {
            $associatedProfileIds = $this->extractAssociatedProfileIds(
                $occurrence,
                $favoriteProfileIdSet,
            );
            if ($associatedProfileIds === []) {
                continue;
            }

            foreach ($associatedProfileIds as $profileId) {
                $currentState = $states[$profileId] ?? [
                    'live_now' => null,
                    'next' => null,
                    'last' => null,
                ];

                $startsAt = $occurrence->starts_at;
                $effectiveEndsAt = $occurrence->effective_ends_at ?? $occurrence->ends_at;
                if ($startsAt instanceof Carbon && $effectiveEndsAt instanceof Carbon) {
                    if ($startsAt->lessThanOrEqualTo($now) && $effectiveEndsAt->greaterThan($now)) {
                        $currentLive = $currentState['live_now'] ?? null;
                        if (! $currentLive instanceof EventOccurrence || $this->startsBefore($occurrence, $currentLive)) {
                            $currentState['live_now'] = $occurrence;
                        }
                    }
                }

                if ($startsAt instanceof Carbon && $startsAt->greaterThanOrEqualTo($now)) {
                    $currentNext = $currentState['next'] ?? null;
                    if (! $currentNext instanceof EventOccurrence || $this->startsBefore($occurrence, $currentNext)) {
                        $currentState['next'] = $occurrence;
                    }
                }

                $states[$profileId] = $currentState;
            }
        }

        return $states;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, EventOccurrence>
     */
    private function loadLastOccurrenceStates(array $profileIds): array
    {
        $normalizedProfileIds = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $profileIds,
        ), static fn (string $value): bool => $value !== '')));

        if ($normalizedProfileIds === []) {
            return [];
        }

        $profileIdCandidates = [];
        foreach ($normalizedProfileIds as $profileId) {
            foreach ($this->buildProfileIdCandidates($profileId) as $candidate) {
                $profileIdCandidates[] = $candidate;
            }
        }

        $now = Carbon::now();
        $occurrences = EventOccurrence::query()
            ->where('deleted_at', null)
            ->where('is_event_published', true)
            ->where(static function ($query) use ($now): void {
                $query->where('effective_ends_at', '<=', $now)
                    ->orWhere(static function ($query) use ($now): void {
                        $query->whereNull('effective_ends_at')
                            ->where('ends_at', '<=', $now);
                    })
                    ->orWhere(static function ($query) use ($now): void {
                        $query->whereNull('effective_ends_at')
                            ->whereNull('ends_at')
                            ->where('starts_at', '<', $now);
                    });
            })
            ->where(static function ($query) use ($profileIdCandidates): void {
                $query->where(static function ($query) use ($profileIdCandidates): void {
                    $query->where('place_ref.type', 'account_profile')
                        ->where(static function ($query) use ($profileIdCandidates): void {
                            $query->whereIn('place_ref.id', $profileIdCandidates)
                                ->orWhereIn('place_ref._id', $profileIdCandidates);
                        });
                })->orWhereRaw([
                    'event_parties' => [
                        '$elemMatch' => [
                            'party_ref_id' => ['$in' => $profileIdCandidates],
                        ],
                    ],
                ]);
            })
            ->orderBy('starts_at', 'desc')
            ->orderBy('_id', 'desc')
            ->get([
                '_id',
                'starts_at',
                'place_ref',
                'event_parties',
            ]);

        $states = [];
        $favoriteProfileIdSet = array_fill_keys($normalizedProfileIds, true);

        foreach ($occurrences as $occurrence) {
            $associatedProfileIds = $this->extractAssociatedProfileIds(
                $occurrence,
                $favoriteProfileIdSet,
            );
            if ($associatedProfileIds === []) {
                continue;
            }

            foreach ($associatedProfileIds as $profileId) {
                $currentLast = $states[$profileId] ?? null;
                if (! $currentLast instanceof EventOccurrence || $this->startsAfter($occurrence, $currentLast)) {
                    $states[$profileId] = $occurrence;
                }
            }
        }

        return $states;
    }

    /**
     * @param  array<string, bool>  $favoriteProfileIdSet
     * @return array<int, string>
     */
    private function extractAssociatedProfileIds(
        EventOccurrence $occurrence,
        array $favoriteProfileIdSet,
    ): array {
        $profileIds = [];

        $placeRef = $this->normalizeArray($occurrence->getAttribute('place_ref'));
        if (($placeRef['type'] ?? null) === 'account_profile') {
            $placeRefId = $this->extractEmbeddedId($placeRef);
            if ($placeRefId !== '' && isset($favoriteProfileIdSet[$placeRefId])) {
                $profileIds[$placeRefId] = $placeRefId;
            }
        }

        foreach ($this->normalizeList($occurrence->getAttribute('event_parties')) as $eventParty) {
            $partyRefId = trim((string) ($eventParty['party_ref_id'] ?? ''));
            if ($partyRefId !== '' && isset($favoriteProfileIdSet[$partyRefId])) {
                $profileIds[$partyRefId] = $partyRefId;
            }
        }

        return array_values($profileIds);
    }

    /**
     * @return array{profile_id:string,favorite_id:string,favorited_at:\DateTimeInterface|null,sort_block:int,sort_upcoming_occurrence_at:\DateTimeInterface|null,payload:array<string,mixed>}
     */
    private function buildRow(
        FavoriteEdge $edge,
        AccountProfile $profile,
        ?EventOccurrence $liveNowOccurrence,
        ?EventOccurrence $nextOccurrence,
        ?EventOccurrence $lastOccurrence,
    ): array {
        $profileId = (string) $profile->getAttribute('_id');
        $profileSlug = trim((string) ($profile->slug ?? ''));
        $canOpenPublicDetail = $profileSlug !== ''
            && $this->typeSetProvider->isPubliclyNavigable((string) ($profile->profile_type ?? ''));
        $publicDetailPath = $canOpenPublicDetail ? '/parceiro/'.$profileSlug : null;

        $liveNowOccurrenceId = $liveNowOccurrence ? (string) $liveNowOccurrence->getAttribute('_id') : null;
        $liveNowOccurrenceAt = $liveNowOccurrence?->starts_at;
        $nextOccurrenceId = $nextOccurrence ? (string) $nextOccurrence->getAttribute('_id') : null;
        $nextOccurrenceAt = $nextOccurrence?->starts_at;
        $lastOccurrenceAt = $lastOccurrence?->starts_at;
        $eventNavigationOccurrence = $liveNowOccurrence ?? $nextOccurrence;
        $eventTargetOccurrenceId = $eventNavigationOccurrence
            ? (string) $eventNavigationOccurrence->getAttribute('_id')
            : null;
        $eventTargetSlug = $eventNavigationOccurrence?->slug
            ? trim((string) $eventNavigationOccurrence->slug)
            : null;
        $eventTargetPath = $this->buildEventTargetPath(
            $eventTargetSlug,
            $eventTargetOccurrenceId,
        );

        $sortBlock = $liveNowOccurrenceAt instanceof \DateTimeInterface
            ? 0
            : ($nextOccurrenceAt instanceof \DateTimeInterface ? 1 : 2);

        return [
            'profile_id' => $profileId,
            'favorite_id' => (string) $edge->getAttribute('_id'),
            'favorited_at' => $edge->favorited_at,
            'sort_block' => $sortBlock,
            'sort_upcoming_occurrence_at' => $sortBlock === 1 ? $nextOccurrenceAt : null,
            'payload' => [
                'favorite_id' => (string) $edge->getAttribute('_id'),
                'registry_key' => 'account_profile',
                'target_type' => 'account_profile',
                'target_id' => $profileId,
                'favorited_at' => $this->formatDate($edge->favorited_at),
                'target' => [
                    'id' => $profileId,
                    'slug' => $profileSlug,
                    'display_name' => (string) ($profile->display_name ?? ''),
                    'avatar_url' => $profile->avatar_url ?? null,
                    'cover_url' => $profile->cover_url ?? null,
                    'profile_type' => $profile->profile_type ? (string) $profile->profile_type : null,
                    'can_open_public_detail' => $canOpenPublicDetail,
                    'public_detail_path' => $publicDetailPath,
                ],
                'occurrence_state' => [
                    'live_now_event_occurrence_id' => $liveNowOccurrenceId,
                    'live_now_event_occurrence_at' => $this->formatDate($liveNowOccurrenceAt),
                    'next_event_occurrence_id' => $nextOccurrenceId,
                    'next_event_occurrence_at' => $this->formatDate($nextOccurrenceAt),
                    'last_event_occurrence_at' => $this->formatDate($lastOccurrenceAt),
                ],
                'navigation' => [
                    'kind' => $eventTargetPath !== null ? 'event' : 'account_profile',
                    'target_slug' => $eventTargetPath !== null ? $eventTargetSlug : $profileSlug,
                    'target_path' => $eventTargetPath ?? $publicDetailPath,
                    'profile_target_path' => $publicDetailPath,
                    'event_target_path' => $eventTargetPath,
                    'event_target_slug' => $eventTargetSlug,
                    'event_occurrence_id' => $eventTargetOccurrenceId,
                    'can_open_public_detail' => $canOpenPublicDetail,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyLastOccurrenceState(
        array $payload,
        ?EventOccurrence $lastOccurrence,
    ): array {
        $occurrenceState = is_array($payload['occurrence_state'] ?? null)
            ? $payload['occurrence_state']
            : [];
        $occurrenceState['last_event_occurrence_at'] = $this->formatDate(
            $lastOccurrence?->starts_at,
        );
        $payload['occurrence_state'] = $occurrenceState;

        return $payload;
    }

    private function compareRows(array $left, array $right): int
    {
        $blockCompare = (int) ($left['sort_block'] ?? 2) <=> (int) ($right['sort_block'] ?? 2);
        if ($blockCompare !== 0) {
            return $blockCompare;
        }

        if ((int) ($left['sort_block'] ?? 2) === 1) {
            $upcomingCompare = $this->compareDatesAscending(
                $left['sort_upcoming_occurrence_at'] ?? null,
                $right['sort_upcoming_occurrence_at'] ?? null,
            );
            if ($upcomingCompare !== 0) {
                return $upcomingCompare;
            }
        }

        $favoritedCompare = $this->compareDatesDescending(
            $left['favorited_at'] ?? null,
            $right['favorited_at'] ?? null,
        );
        if ($favoritedCompare !== 0) {
            return $favoritedCompare;
        }

        return strcmp(
            (string) ($left['favorite_id'] ?? ''),
            (string) ($right['favorite_id'] ?? ''),
        );
    }

    private function compareDatesAscending(
        mixed $left,
        mixed $right,
    ): int {
        if (! $left instanceof \DateTimeInterface && ! $right instanceof \DateTimeInterface) {
            return 0;
        }

        if (! $left instanceof \DateTimeInterface) {
            return 1;
        }

        if (! $right instanceof \DateTimeInterface) {
            return -1;
        }

        return $left->getTimestamp() <=> $right->getTimestamp();
    }

    private function compareDatesDescending(
        mixed $left,
        mixed $right,
    ): int {
        if (! $left instanceof \DateTimeInterface && ! $right instanceof \DateTimeInterface) {
            return 0;
        }

        if (! $left instanceof \DateTimeInterface) {
            return 1;
        }

        if (! $right instanceof \DateTimeInterface) {
            return -1;
        }

        return $right->getTimestamp() <=> $left->getTimestamp();
    }

    private function startsBefore(EventOccurrence $left, EventOccurrence $right): bool
    {
        $leftStartsAt = $left->starts_at;
        $rightStartsAt = $right->starts_at;

        if (! $leftStartsAt instanceof \DateTimeInterface || ! $rightStartsAt instanceof \DateTimeInterface) {
            return false;
        }

        if ($leftStartsAt->getTimestamp() === $rightStartsAt->getTimestamp()) {
            return strcmp((string) $left->getAttribute('_id'), (string) $right->getAttribute('_id')) < 0;
        }

        return $leftStartsAt->getTimestamp() < $rightStartsAt->getTimestamp();
    }

    private function startsAfter(EventOccurrence $left, EventOccurrence $right): bool
    {
        $leftStartsAt = $left->starts_at;
        $rightStartsAt = $right->starts_at;

        if (! $leftStartsAt instanceof \DateTimeInterface || ! $rightStartsAt instanceof \DateTimeInterface) {
            return false;
        }

        if ($leftStartsAt->getTimestamp() === $rightStartsAt->getTimestamp()) {
            return strcmp((string) $left->getAttribute('_id'), (string) $right->getAttribute('_id')) > 0;
        }

        return $leftStartsAt->getTimestamp() > $rightStartsAt->getTimestamp();
    }

    private function buildEventTargetPath(?string $eventSlug, ?string $occurrenceId): ?string
    {
        $normalizedSlug = trim((string) $eventSlug);
        $normalizedOccurrenceId = trim((string) $occurrenceId);

        if ($normalizedSlug === '' || $normalizedOccurrenceId === '') {
            return null;
        }

        return '/agenda/evento/'.$normalizedSlug.'?occurrence='.rawurlencode($normalizedOccurrenceId);
    }

    /**
     * @return array<int, string|ObjectId>
     */
    private function buildProfileIdCandidates(string $profileId): array
    {
        $candidates = [$profileId];

        if ($this->looksLikeObjectId($profileId)) {
            $candidates[] = new ObjectId($profileId);
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeList(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = $this->normalizeArray($item);
        }

        return $normalized;
    }

    /**
     * Accept both legacy `id` and production-shaped `_id` embedded references.
     */
    private function extractEmbeddedId(array $payload): string
    {
        foreach (['id', '_id'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format(DATE_ATOM);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
