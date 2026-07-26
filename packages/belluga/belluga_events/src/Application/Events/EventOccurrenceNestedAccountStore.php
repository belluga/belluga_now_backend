<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Contracts\EventProfileResolverContract;
use Belluga\Events\Contracts\EventTenantContextContract;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Connection;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class EventOccurrenceNestedAccountStore
{
    public const COLLECTION = 'accounts_nested';

    public const PARENT_TYPE = 'event_occurrence';

    private const DOC_TYPE_HEAD = 'group_head';

    private const DOC_TYPE_MEMBER = 'member_row';

    private const CURSOR_VERSION = 1;

    private const CURSOR_SCOPE = 'event_related_profile_members';

    public function __construct(
        private readonly EventTenantContextContract $tenantContext,
        private readonly EventProfileResolverContract $eventProfileResolver,
        private readonly EventProfileGroupMemberStore $legacyProfileGroupMemberStore,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    public function syncOccurrenceGroups(string $eventId, EventOccurrence $occurrence, array $groups): void
    {
        $eventId = trim($eventId);
        $occurrenceId = trim((string) $occurrence->getKey());
        if ($eventId === '' || $occurrenceId === '') {
            return;
        }

        $filter = [
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $occurrenceId,
        ];

        $this->collection()->deleteMany($filter);

        $rows = $this->rowsForOccurrence($eventId, $occurrenceId, $groups);
        if ($rows !== []) {
            $this->collection()->insertMany($rows);
        }
    }

    /**
     * @param  array<int, string>  $activeOccurrenceIds
     */
    public function purgeMissingOccurrences(string $eventId, array $activeOccurrenceIds): void
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return;
        }

        $normalizedOccurrenceIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $occurrenceId): string => trim((string) $occurrenceId),
            $activeOccurrenceIds,
        ), static fn (string $occurrenceId): bool => $occurrenceId !== '')));

        $filter = [
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
        ];

        if ($normalizedOccurrenceIds === []) {
            $this->collection()->deleteMany($filter);

            return;
        }

        $filter['parent_id'] = ['$nin' => $normalizedOccurrenceIds];
        $this->collection()->deleteMany($filter);
    }

    public function purgeByEventId(string $eventId): void
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return;
        }

        $this->collection()->deleteMany([
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
        ]);
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, string>
     */
    public function eventIdsForMemberProfiles(array $profileIds): array
    {
        $normalizedProfileIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $profileId): string => trim((string) $profileId),
            $profileIds,
        ), static fn (string $profileId): bool => $profileId !== '')));

        if ($normalizedProfileIds === []) {
            return [];
        }

        $rows = iterator_to_array($this->collection()->find(
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'doc_type' => self::DOC_TYPE_MEMBER,
                'nested_profile.id' => ['$in' => $normalizedProfileIds],
            ],
            [
                'projection' => ['event_id' => 1],
            ],
        ));

        $eventIds = [];
        foreach ($rows as $row) {
            $eventId = trim((string) (($this->documentToArray($row)['event_id'] ?? null) ?: ''));
            if ($eventId !== '' && ! in_array($eventId, $eventIds, true)) {
                $eventIds[] = $eventId;
            }
        }

        return $eventIds;
    }

    public function materializeLegacyIfNeeded(Event $event, bool $includeTrashedOccurrences = false): void
    {
        $eventId = trim((string) $event->getKey());
        if ($eventId === '') {
            return;
        }

        $existing = $this->collection()->countDocuments([
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
        ]);
        if ($existing > 0) {
            return;
        }

        $eventGroups = $this->legacyProfileGroupMemberStore->inflateGroupsWithMembers(
            $event->profile_groups ?? [],
            'event',
            $eventId,
        );
        $occurrenceQuery = $includeTrashedOccurrences
            ? EventOccurrence::withTrashed()
            : EventOccurrence::query();
        $occurrences = $occurrenceQuery
            ->where('event_id', $eventId)
            ->orderBy('starts_at')
            ->get();

        foreach ($occurrences as $occurrence) {
            if (! $occurrence instanceof EventOccurrence) {
                continue;
            }

            $occurrenceId = trim((string) $occurrence->getKey());
            if ($occurrenceId === '') {
                continue;
            }

            $ownGroups = $this->legacyProfileGroupMemberStore->inflateGroupsWithMembers(
                $occurrence->own_profile_groups ?? [],
                'occurrence',
                $occurrenceId,
            );

            $this->syncOccurrenceGroups(
                $eventId,
                $occurrence,
                $this->mergeGroups($eventGroups, $ownGroups),
            );
        }
    }

    /**
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @return array<int, array{id:string,label:string,order:int,member_count:int,members_path:string}>
     */
    public function mergedPublicGroupMetadata(
        Event $event,
        iterable $occurrences,
        string $eventRouteKey,
    ): array {
        $eventId = trim((string) $event->getKey());
        if ($eventId === '') {
            return [];
        }

        $eventRouteKey = trim($eventRouteKey);
        if ($eventRouteKey === '') {
            $eventRouteKey = trim((string) ($event->slug ?? ''));
        }
        if ($eventRouteKey === '') {
            $eventRouteKey = $eventId;
        }

        $this->materializeLegacyIfNeeded($event);

        $occurrenceOrderById = $this->occurrenceOrderById($occurrences);
        $headRows = iterator_to_array($this->collection()->find(
            [
                'tenant_id' => $this->tenantId(),
                'event_id' => $eventId,
                'parent_type' => self::PARENT_TYPE,
                'doc_type' => self::DOC_TYPE_HEAD,
            ],
            [
                'sort' => ['group_order' => 1, '_id' => 1],
            ],
        ));
        if ($headRows === []) {
            return [];
        }

        $memberRows = iterator_to_array($this->collection()->find(
            [
                'tenant_id' => $this->tenantId(),
                'event_id' => $eventId,
                'parent_type' => self::PARENT_TYPE,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                'projection' => ['parent_id' => 1, 'group_key' => 1, 'nested_profile.id' => 1],
                'sort' => ['item_order' => 1, '_id' => 1],
            ],
        ));

        $memberIdsByHead = [];
        foreach ($memberRows as $row) {
            $document = $this->documentToArray($row);
            $parentId = trim((string) ($document['parent_id'] ?? ''));
            $groupKey = trim((string) ($document['group_key'] ?? ''));
            $memberId = trim((string) (($this->normalizeArray($document['nested_profile'] ?? [])['id'] ?? null) ?: ''));
            if ($parentId === '' || $groupKey === '' || $memberId === '') {
                continue;
            }

            $headKey = $this->headKey($parentId, $groupKey);
            $memberIdsByHead[$headKey] ??= [];
            if (! in_array($memberId, $memberIdsByHead[$headKey], true)) {
                $memberIdsByHead[$headKey][] = $memberId;
            }
        }

        $buckets = [];
        foreach ($headRows as $row) {
            $document = $this->documentToArray($row);
            $parentId = trim((string) ($document['parent_id'] ?? ''));
            $groupKey = trim((string) ($document['group_key'] ?? ''));
            $label = trim((string) ($document['group_label'] ?? ''));
            if ($parentId === '' || $groupKey === '' || $label === '') {
                continue;
            }

            $normalizedLabel = $this->normalizedLabel($label);
            $tabId = $this->tabId($normalizedLabel);
            $occurrenceOrder = $occurrenceOrderById[$parentId] ?? PHP_INT_MAX;
            $groupOrder = (int) ($document['group_order'] ?? 0);
            $headKey = $this->headKey($parentId, $groupKey);

            if (! isset($buckets[$tabId])) {
                $buckets[$tabId] = [
                    'id' => $tabId,
                    'label' => $label,
                    'normalized_label' => $normalizedLabel,
                    'first_occurrence_order' => $occurrenceOrder,
                    'group_order' => $groupOrder,
                    'member_ids' => [],
                ];
            }

            $buckets[$tabId]['first_occurrence_order'] = min(
                (int) $buckets[$tabId]['first_occurrence_order'],
                $occurrenceOrder,
            );
            $buckets[$tabId]['group_order'] = min(
                (int) $buckets[$tabId]['group_order'],
                $groupOrder,
            );

            foreach ($memberIdsByHead[$headKey] ?? [] as $memberId) {
                if (! in_array($memberId, $buckets[$tabId]['member_ids'], true)) {
                    $buckets[$tabId]['member_ids'][] = $memberId;
                }
            }
        }

        $groups = array_values(array_filter(array_map(
            function (array $bucket) use ($eventRouteKey): ?array {
                $memberCount = count($bucket['member_ids']);
                if ($memberCount === 0) {
                    return null;
                }

                return [
                    'id' => (string) $bucket['id'],
                    'label' => (string) $bucket['label'],
                    'order' => (int) $bucket['group_order'],
                    'member_count' => $memberCount,
                    'members_path' => "/api/v1/events/{$eventRouteKey}/related_profile_tabs/{$bucket['id']}/members",
                    '_sort' => [
                        (int) $bucket['first_occurrence_order'],
                        (int) $bucket['group_order'],
                        (string) $bucket['normalized_label'],
                        (string) $bucket['id'],
                    ],
                ];
            },
            $buckets,
        )));

        usort(
            $groups,
            static fn (array $left, array $right): int => $left['_sort'] <=> $right['_sort'],
        );

        return array_values(array_map(static function (array $group, int $index): array {
            unset($group['_sort']);
            $group['order'] = $index;

            return $group;
        }, $groups, array_keys($groups)));
    }

    /**
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @return array{data: array<int, array<string, mixed>>, next_cursor: ?string}
     */
    public function publicMemberPage(
        Event $event,
        iterable $occurrences,
        string $tabId,
        int $defaultPerPage,
        ?int $suppliedPerPage,
        ?string $cursor,
    ): array {
        $eventId = trim((string) $event->getKey());
        if ($eventId === '') {
            throw new NotFoundHttpException;
        }

        $this->materializeLegacyIfNeeded($event);

        $resolvedTabId = trim($tabId);
        if ($resolvedTabId === '') {
            throw new NotFoundHttpException;
        }

        $perPage = max(1, $defaultPerPage);
        $offset = 0;
        if ($cursor !== null) {
            $payload = $this->decodeCursor($cursor);
            if (($payload['scope'] ?? null) !== self::CURSOR_SCOPE
                || ($payload['event_id'] ?? null) !== $eventId
                || ($payload['tab_id'] ?? null) !== $resolvedTabId) {
                throw ValidationException::withMessages([
                    'cursor' => ['Event related-profile cursor is invalid for this event or tab.'],
                ]);
            }

            $cursorPerPage = (int) ($payload['per_page'] ?? 0);
            if ($suppliedPerPage !== null && $suppliedPerPage !== $cursorPerPage) {
                throw ValidationException::withMessages([
                    'per_page' => ['Event related-profile cursor fixes the page size for continuation requests.'],
                ]);
            }

            $perPage = max(1, $cursorPerPage);
            $offset = max(0, (int) ($payload['offset'] ?? 0));
        } elseif ($suppliedPerPage !== null) {
            $perPage = max(1, $suppliedPerPage);
        }

        $bucket = $this->memberRowsForTab($eventId, $occurrences, $resolvedTabId);
        if ($bucket === []) {
            throw new NotFoundHttpException;
        }

        $pageRows = array_slice($bucket, $offset, $perPage + 1);
        $visibleRows = array_slice($pageRows, 0, $perPage);

        $nextCursor = null;
        if (count($pageRows) > $perPage) {
            $nextCursor = Crypt::encryptString(json_encode([
                'version' => self::CURSOR_VERSION,
                'scope' => self::CURSOR_SCOPE,
                'event_id' => $eventId,
                'tab_id' => $resolvedTabId,
                'per_page' => $perPage,
                'offset' => $offset + $perPage,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'data' => array_values(array_map(
                fn (array $row): array => $this->formatPublicMemberRow($row),
                $visibleRows,
            )),
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @return array<int, array<string, mixed>>
     */
    private function memberRowsForTab(string $eventId, iterable $occurrences, string $tabId): array
    {
        $occurrenceOrderById = $this->occurrenceOrderById($occurrences);
        $memberRows = iterator_to_array($this->collection()->find(
            [
                'tenant_id' => $this->tenantId(),
                'event_id' => $eventId,
                'parent_type' => self::PARENT_TYPE,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                'sort' => ['group_order' => 1, 'item_order' => 1, '_id' => 1],
            ],
        ));

        $deduped = [];
        $seenProfileIds = [];
        foreach ($memberRows as $row) {
            $document = $this->documentToArray($row);
            $groupLabel = trim((string) ($document['group_label'] ?? ''));
            $normalizedLabel = $this->normalizedLabel($groupLabel);
            if ($normalizedLabel === '' || $this->tabId($normalizedLabel) !== $tabId) {
                continue;
            }

            $nestedProfile = $this->normalizeArray($document['nested_profile'] ?? []);
            $profileType = trim((string) ($nestedProfile['profile_type'] ?? ''));
            $profileId = trim((string) ($nestedProfile['id'] ?? ''));
            if (
                $profileId === ''
                || $profileType === ''
                || ! $this->eventProfileResolver->isProfileTypeQueryable($profileType)
                || isset($seenProfileIds[$profileId])
            ) {
                continue;
            }

            $parentId = trim((string) ($document['parent_id'] ?? ''));
            $document['_occurrence_order'] = $occurrenceOrderById[$parentId] ?? PHP_INT_MAX;
            $seenProfileIds[$profileId] = true;
            $deduped[] = $document;
        }

        usort(
            $deduped,
            static fn (array $left, array $right): int => [
                (int) ($left['_occurrence_order'] ?? PHP_INT_MAX),
                (int) ($left['group_order'] ?? 0),
                (int) ($left['item_order'] ?? 0),
                (string) ($left['_id'] ?? ''),
            ] <=> [
                (int) ($right['_occurrence_order'] ?? PHP_INT_MAX),
                (int) ($right['group_order'] ?? 0),
                (int) ($right['item_order'] ?? 0),
                (string) ($right['_id'] ?? ''),
            ],
        );

        return $deduped;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPublicMemberRow(array $row): array
    {
        $nestedProfile = $this->normalizeArray($row['nested_profile'] ?? []);
        $slug = trim((string) ($nestedProfile['slug'] ?? ''));
        $profileType = trim((string) ($nestedProfile['profile_type'] ?? ''));
        $canOpenPublicDetail = $slug !== ''
            && $profileType !== ''
            && $this->eventProfileResolver->isProfileTypePubliclyNavigable($profileType);

        return [
            'id' => trim((string) ($nestedProfile['id'] ?? '')),
            'display_name' => trim((string) ($nestedProfile['label'] ?? '')),
            'profile_type' => $profileType,
            'slug' => $slug === '' ? null : $slug,
            'avatar_url' => is_string($nestedProfile['avatar_url'] ?? null) ? $nestedProfile['avatar_url'] : null,
            'cover_url' => is_string($nestedProfile['cover_url'] ?? null) ? $nestedProfile['cover_url'] : null,
            'taxonomy_terms' => [],
            'can_open_public_detail' => $canOpenPublicDetail,
            'public_detail_path' => $canOpenPublicDetail ? '/parceiro/'.$slug : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $eventGroups
     * @param  array<int, array<string, mixed>>  $ownGroups
     * @return array<int, array{id:string,label:string,order:int,account_profile_ids:array<int,string>}>
     */
    private function mergeGroups(array $eventGroups, array $ownGroups): array
    {
        $merged = [];
        $indexById = [];
        foreach ([$eventGroups, $ownGroups] as $groupSet) {
            foreach ($groupSet as $group) {
                $id = trim((string) ($group['id'] ?? ''));
                $label = trim((string) ($group['label'] ?? ''));
                if ($id === '' || $label === '') {
                    continue;
                }

                if (! isset($indexById[$id])) {
                    $indexById[$id] = count($merged);
                    $merged[] = [
                        'id' => $id,
                        'label' => $label,
                        'order' => count($merged),
                        'account_profile_ids' => [],
                    ];
                }

                foreach ($this->normalizeArray($group['account_profile_ids'] ?? []) as $rawMemberId) {
                    $memberId = trim((string) $rawMemberId);
                    if (
                        $memberId !== ''
                        && ! in_array($memberId, $merged[$indexById[$id]]['account_profile_ids'], true)
                    ) {
                        $merged[$indexById[$id]]['account_profile_ids'][] = $memberId;
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function rowsForOccurrence(string $eventId, string $occurrenceId, array $groups): array
    {
        $normalizedGroups = array_values(array_filter(array_map(
            function (array $group): ?array {
                $groupId = trim((string) ($group['id'] ?? ''));
                $label = trim((string) ($group['label'] ?? ''));
                if ($groupId === '' || $label === '') {
                    return null;
                }

                return [
                    'id' => $groupId,
                    'label' => $label,
                    'order' => (int) ($group['order'] ?? 0),
                    'account_profile_ids' => array_values(array_unique(array_filter(array_map(
                        static fn (mixed $memberId): string => trim((string) $memberId),
                        $this->normalizeArray($group['account_profile_ids'] ?? []),
                    ), static fn (string $memberId): bool => $memberId !== ''))),
                ];
            },
            $groups,
        )));

        if ($normalizedGroups === []) {
            return [];
        }

        $profilesById = $this->profilesByIdForIds(array_values(array_unique(array_merge(
            ...array_map(static fn (array $group): array => $group['account_profile_ids'], $normalizedGroups),
        ))));
        $now = new UTCDateTime((int) now()->getTimestampMs());
        $tenantId = $this->tenantId();
        $rows = [];

        foreach ($normalizedGroups as $group) {
            $rows[] = [
                '_id' => $this->headId($occurrenceId, $group['id']),
                'tenant_id' => $tenantId,
                'event_id' => $eventId,
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $occurrenceId,
                'group_key' => $group['id'],
                'group_label' => $group['label'],
                'group_order' => $group['order'],
                'doc_type' => self::DOC_TYPE_HEAD,
                'updated_at' => $now,
            ];

            foreach (array_values($group['account_profile_ids']) as $itemOrder => $memberId) {
                $rows[] = [
                    '_id' => $this->memberId($occurrenceId, $group['id'], $memberId),
                    'tenant_id' => $tenantId,
                    'event_id' => $eventId,
                    'parent_type' => self::PARENT_TYPE,
                    'parent_id' => $occurrenceId,
                    'group_key' => $group['id'],
                    'group_label' => $group['label'],
                    'group_order' => $group['order'],
                    'item_order' => $itemOrder,
                    'doc_type' => self::DOC_TYPE_MEMBER,
                    'nested_profile' => $this->nestedProfileDocument(
                        $memberId,
                        $profilesById[$memberId] ?? null,
                    ),
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $memberIds
     * @return array<string, array<string, mixed>>
     */
    private function profilesByIdForIds(array $memberIds): array
    {
        $normalizedIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $memberId): string => trim((string) $memberId),
            $memberIds,
        ), static fn (string $memberId): bool => $memberId !== '')));

        if ($normalizedIds === []) {
            return [];
        }

        $profilesById = [];
        foreach ($this->eventProfileResolver->resolveNestedAccountProfileSnapshotsByIds($normalizedIds) as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            $profileId = trim((string) ($profile['id'] ?? ''));
            if ($profileId !== '') {
                $profilesById[$profileId] = $profile;
            }
        }

        return $profilesById;
    }

    /**
     * @return array<string, mixed>
     */
    private function nestedProfileDocument(string $memberProfileId, ?array $profile): array
    {
        $memberProfileId = trim($memberProfileId);
        $profileType = trim((string) ($profile['profile_type'] ?? ''));
        $label = trim((string) ($profile['label'] ?? ''));
        $searchKey = trim((string) ($profile['search_key'] ?? ''));
        $slug = trim((string) ($profile['slug'] ?? ''));

        return [
            'id' => $memberProfileId,
            'label' => $label === '' ? null : $label,
            'search_key' => $searchKey === '' ? null : $searchKey,
            'profile_type' => $profileType === '' ? null : $profileType,
            'category' => $profileType === '' ? null : $profileType,
            'taxonomy_terms_flat' => array_values(array_filter(array_map(
                static fn (mixed $term): string => trim((string) $term),
                (array) ($profile['taxonomy_terms_flat'] ?? []),
            ), static fn (string $term): bool => $term !== '')),
            'slug' => $slug === '' ? null : $slug,
            'avatar_url' => is_string($profile['avatar_url'] ?? null) ? $profile['avatar_url'] : null,
            'cover_url' => is_string($profile['cover_url'] ?? null) ? $profile['cover_url'] : null,
        ];
    }

    /**
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @return array<string, int>
     */
    private function occurrenceOrderById(iterable $occurrences): array
    {
        $orderById = [];
        $index = 0;
        foreach ($occurrences as $occurrence) {
            if (! $occurrence instanceof EventOccurrence) {
                continue;
            }

            $occurrenceId = trim((string) $occurrence->getKey());
            if ($occurrenceId === '' || isset($orderById[$occurrenceId])) {
                continue;
            }

            $orderById[$occurrenceId] = $index;
            $index++;
        }

        return $orderById;
    }

    private function normalizedLabel(string $label): string
    {
        $normalized = Str::of($label)->lower()->ascii()->replaceMatches('/\s+/', ' ')->trim()->value();

        return $normalized;
    }

    private function tabId(string $normalizedLabel): string
    {
        return 'event-tab-'.substr(sha1($normalizedLabel), 0, 16);
    }

    private function headKey(string $parentId, string $groupKey): string
    {
        return $parentId.'::'.$groupKey;
    }

    private function headId(string $occurrenceId, string $groupKey): string
    {
        return 'accounts-nested:head:'.self::PARENT_TYPE.':'.$occurrenceId.':'.$groupKey;
    }

    private function memberId(string $occurrenceId, string $groupKey, string $memberId): string
    {
        return 'accounts-nested:member:'.self::PARENT_TYPE.':'.$occurrenceId.':'.$groupKey.':'.$memberId;
    }

    /** @return array<string, mixed> */
    private function decodeCursor(string $cursor): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'cursor' => ['Event related-profile cursor is invalid.'],
            ]);
        }

        if (! is_array($payload) || (int) ($payload['version'] ?? 0) !== self::CURSOR_VERSION) {
            throw ValidationException::withMessages([
                'cursor' => ['Event related-profile cursor is invalid.'],
            ]);
        }

        $expiresAt = $payload['expires_at'] ?? null;
        if (! is_string($expiresAt) || Carbon::parse($expiresAt)->isPast()) {
            throw ValidationException::withMessages([
                'cursor' => ['Event related-profile cursor expired.'],
            ]);
        }

        return $payload;
    }

    /**
     * @return array<int, mixed>|array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentToArray(mixed $document): array
    {
        if ($document instanceof BSONDocument) {
            $document = $document->getArrayCopy();
        }

        if ($document instanceof BSONArray) {
            $document = $document->getArrayCopy();
        }

        return is_array($document) ? $document : [];
    }

    private function tenantId(): string
    {
        $tenantId = trim((string) ($this->tenantContext->resolveCurrentTenantId() ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('Current tenant is required for Event occurrence nested account storage.');
        }

        return $tenantId;
    }

    private function collection(): \MongoDB\Collection
    {
        $connection = DB::connection('tenant');
        if (! $connection instanceof Connection) {
            throw new RuntimeException('A MongoDB tenant connection is required for Event occurrence nested account storage.');
        }

        return $connection->getDatabase()->selectCollection(self::COLLECTION);
    }
}
