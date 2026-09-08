<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Application\Transactions\EventTransactionContext;
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

    private const CURSOR_VERSION = 2;

    private const CURSOR_SCOPE = 'event_related_profile_members';

    private const ADMIN_CURSOR_SCOPE = 'event_admin_occurrence_group_members';

    public function __construct(
        private readonly EventTenantContextContract $tenantContext,
        private readonly EventProfileResolverContract $eventProfileResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array{id:string,label:string,order:int}>
     */
    public function metadataOnly(array $groups): array
    {
        return array_values(array_map(
            static fn (array $group): array => [
                'id' => trim((string) ($group['id'] ?? '')),
                'label' => (string) ($group['label'] ?? ''),
                'order' => (int) ($group['order'] ?? 0),
            ],
            array_values(array_filter(
                $groups,
                static fn (array $group): bool => trim((string) ($group['id'] ?? '')) !== '',
            )),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    public function syncOccurrenceGroupMetadataWithinContext(
        EventTransactionContext $context,
        string $eventId,
        EventOccurrence $occurrence,
        array $groups,
    ): void {
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
                ];
            },
            $groups,
        )));

        if ($normalizedGroups === []) {
            $context->collection(self::COLLECTION)->deleteMany($filter, $context->rawOptions());

            return;
        }

        $now = new UTCDateTime((int) now()->getTimestampMs());
        $keepGroupIds = [];

        foreach ($normalizedGroups as $group) {
            $groupId = (string) $group['id'];
            $keepGroupIds[] = $groupId;

            $context->collection(self::COLLECTION)->updateOne(
                [
                    '_id' => $this->headId($occurrenceId, $groupId),
                ],
                [
                    '$set' => [
                        'tenant_id' => $filter['tenant_id'],
                        'event_id' => $eventId,
                        'parent_type' => self::PARENT_TYPE,
                        'parent_id' => $occurrenceId,
                        'group_key' => $groupId,
                        'group_label' => (string) $group['label'],
                        'group_order' => (int) $group['order'],
                        'doc_type' => self::DOC_TYPE_HEAD,
                        'updated_at' => $now,
                    ],
                ],
                [...$context->rawOptions(), 'upsert' => true],
            );

        }

        $context->collection(self::COLLECTION)->deleteMany([
            ...$filter,
            'group_key' => ['$nin' => array_values(array_unique($keepGroupIds))],
        ], $context->rawOptions());
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

    /** @param array<int, string> $activeOccurrenceIds */
    public function purgeMissingOccurrencesWithinContext(
        EventTransactionContext $context,
        string $eventId,
        array $activeOccurrenceIds,
    ): void {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return;
        }
        $activeOccurrenceIds = array_values(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $activeOccurrenceIds,
        ), static fn (string $id): bool => $id !== ''));
        $filter = ['tenant_id' => $this->tenantId(), 'event_id' => $eventId, 'parent_type' => self::PARENT_TYPE];
        $filter['parent_id'] = $activeOccurrenceIds === [] ? ['$exists' => true] : ['$nin' => $activeOccurrenceIds];
        $context->collection(self::COLLECTION)->deleteMany($filter, $context->rawOptions());
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

    public function purgeByEventIdWithinContext(EventTransactionContext $context, string $eventId): void
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return;
        }

        $context->collection(self::COLLECTION)->deleteMany([
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
        ], $context->rawOptions());
    }

    public function deleteOccurrenceGroupWithinContext(
        EventTransactionContext $context,
        string $eventId,
        string $occurrenceId,
        string $groupId,
    ): void {
        $head = $this->findOccurrenceGroupHeadOrFail($eventId, $occurrenceId, $groupId, $context);
        $context->collection(self::COLLECTION)->deleteMany([
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $occurrenceId,
            'group_key' => (string) $head['group_key'],
        ], $context->rawOptions());
        $context->collection(self::COLLECTION)->updateMany([
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $occurrenceId,
            'doc_type' => self::DOC_TYPE_HEAD,
            'group_order' => ['$gt' => (int) ($head['group_order'] ?? 0)],
        ], ['$inc' => ['group_order' => -1]], $context->rawOptions());
    }

    /** @return array<int, array{id:string,order:int}> */
    public function orderedGroupPositionsWithinContext(
        EventTransactionContext $context,
        string $eventId,
        string $occurrenceId,
    ): array {
        $rows = $context->collection(self::COLLECTION)->find([
            'tenant_id' => $this->tenantId(),
            'event_id' => trim($eventId),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => trim($occurrenceId),
            'doc_type' => self::DOC_TYPE_HEAD,
        ], [
            ...$context->rawOptions(),
            'projection' => ['group_key' => 1, 'group_order' => 1],
            'sort' => ['group_order' => 1, '_id' => 1],
        ]);

        return array_values(array_map(function (array|object $row): array {
            $document = $this->documentToArray($row);

            return [
                'id' => trim((string) ($document['group_key'] ?? '')),
                'order' => (int) ($document['group_order'] ?? -1),
            ];
        }, iterator_to_array($rows)));
    }

    public function swapAdjacentGroupOrdersWithinContext(
        EventTransactionContext $context,
        string $eventId,
        string $occurrenceId,
        array $moved,
        array $neighbor,
    ): void {
        $eventId = trim($eventId);
        $occurrenceId = trim($occurrenceId);
        $movedId = trim((string) ($moved['id'] ?? ''));
        $neighborId = trim((string) ($neighbor['id'] ?? ''));
        if ($eventId === '' || $occurrenceId === '' || $movedId === '' || $neighborId === '') {
            throw new NotFoundHttpException;
        }

        $result = $context->collection(self::COLLECTION)->updateMany([
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $occurrenceId,
            'doc_type' => self::DOC_TYPE_HEAD,
            '$or' => [
                ['group_key' => $movedId, 'group_order' => (int) $moved['order']],
                ['group_key' => $neighborId, 'group_order' => (int) $neighbor['order']],
            ],
        ], [[
            '$set' => [
                'group_order' => [
                    '$cond' => [
                        ['$eq' => ['$group_key', ['$literal' => $movedId]]],
                        (int) $neighbor['order'],
                        (int) $moved['order'],
                    ],
                ],
                'updated_at' => '$$NOW',
            ],
        ]], $context->rawOptions());

        if ($result->getMatchedCount() !== 2 || $result->getModifiedCount() !== 2) {
            throw new RuntimeException('Event occurrence nested group heads changed during reorder.');
        }
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, string>
     */
    public function occurrenceIdsForMemberProfiles(array $profileIds): array
    {
        $normalizedProfileIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $profileId): string => trim((string) $profileId),
            $profileIds,
        ), static fn (string $profileId): bool => $profileId !== '')));

        if ($normalizedProfileIds === []) {
            return [];
        }

        return $this->normalizedStrings($this->collection()->distinct(
            'parent_id',
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'doc_type' => self::DOC_TYPE_MEMBER,
                'nested_profile.id' => ['$in' => $normalizedProfileIds],
            ],
        ));
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<int, string>
     */
    public function eventIdsForMemberProfiles(array $profileIds): array
    {
        $normalizedProfileIds = $this->normalizedStrings($profileIds);
        if ($normalizedProfileIds === []) {
            return [];
        }

        return $this->normalizedStrings($this->collection()->distinct(
            'event_id',
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'doc_type' => self::DOC_TYPE_MEMBER,
                'nested_profile.id' => ['$in' => $normalizedProfileIds],
            ],
        ));
    }

    /**
     * @return array<int, array{id:string,label:string,order:int,member_count:int,members_path:string}>
     */
    public function adminOccurrenceGroupMetadata(
        EventOccurrence $occurrence,
        string $eventRouteKey,
        ?EventTransactionContext $context = null,
    ): array {
        $eventId = trim((string) ($occurrence->event_id ?? ''));
        $occurrenceId = trim((string) $occurrence->getKey());
        if ($eventId === '' || $occurrenceId === '') {
            return [];
        }

        $collection = $context?->collection(self::COLLECTION) ?? $this->collection();
        $rawOptions = $context?->rawOptions() ?? [];
        $headRows = iterator_to_array($collection->find(
            [
                'tenant_id' => $this->tenantId(),
                'event_id' => $eventId,
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $occurrenceId,
                'doc_type' => self::DOC_TYPE_HEAD,
            ],
            [
                ...$rawOptions,
                'sort' => ['group_order' => 1, '_id' => 1],
            ],
        ));

        if ($headRows === []) {
            $embeddedGroups = $this->normalizeArray($occurrence->own_profile_groups ?? []);
            if ($embeddedGroups !== []) {
                throw new NotFoundHttpException;
            }

            return [];
        }

        $memberRows = $collection->aggregate([
            ['$match' => [
                'tenant_id' => $this->tenantId(),
                'event_id' => $eventId,
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $occurrenceId,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ]],
            ['$group' => [
                '_id' => ['group_key' => '$group_key', 'member_id' => '$nested_profile.id'],
            ]],
            ['$group' => ['_id' => '$_id.group_key', 'member_count' => ['$sum' => 1]]],
        ], $rawOptions);

        $memberCountsByGroup = [];
        foreach ($memberRows as $row) {
            $document = $this->documentToArray($row);
            $groupKey = trim((string) ($document['_id'] ?? ''));
            if ($groupKey === '') {
                continue;
            }
            $memberCountsByGroup[$groupKey] = max(0, (int) ($document['member_count'] ?? 0));
        }

        $eventRouteKey = trim($eventRouteKey);
        if ($eventRouteKey === '') {
            $eventRouteKey = $eventId;
        }

        $groups = [];
        foreach ($headRows as $row) {
            $document = $this->documentToArray($row);
            $groupKey = trim((string) ($document['group_key'] ?? ''));
            $label = trim((string) ($document['group_label'] ?? ''));
            if ($groupKey === '' || $label === '') {
                continue;
            }

            $groups[] = [
                'id' => $groupKey,
                'label' => $label,
                'order' => (int) ($document['group_order'] ?? count($groups)),
                'member_count' => (int) ($memberCountsByGroup[$groupKey] ?? 0),
                'members_path' => "/admin/api/v1/events/{$eventRouteKey}/occurrences/{$occurrenceId}/profile_groups/{$groupKey}/members",
            ];
        }

        return $groups;
    }

    /** @return array{id:string,label:string,_changed:bool} */
    public function renameOccurrenceGroupLabel(
        EventTransactionContext $context,
        string $eventId,
        string $occurrenceId,
        string $groupId,
        string $label,
    ): array {
        $eventId = trim($eventId);
        $occurrenceId = trim($occurrenceId);
        $groupId = trim($groupId);
        $label = trim($label);
        if ($eventId === '' || $occurrenceId === '' || $groupId === '') {
            throw new NotFoundHttpException;
        }

        $headFilter = [
            '_id' => $this->headId($occurrenceId, $groupId),
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $occurrenceId,
            'group_key' => $groupId,
            'doc_type' => self::DOC_TYPE_HEAD,
        ];
        $result = $context->collection(self::COLLECTION)->updateOne(
            $headFilter,
            [[
                '$set' => [
                    'group_label' => ['$literal' => $label],
                    'updated_at' => [
                        '$cond' => [
                            ['$ne' => ['$group_label', ['$literal' => $label]]],
                            '$$NOW',
                            '$updated_at',
                        ],
                    ],
                ],
            ]],
            $context->rawOptions(),
        );
        if ($result->getMatchedCount() !== 1) {
            throw new NotFoundHttpException;
        }

        return [
            'id' => $groupId,
            'label' => $label,
            '_changed' => $result->getModifiedCount() === 1,
        ];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>,next_cursor:?string}
     */
    public function adminOccurrenceMemberPage(
        EventOccurrence $occurrence,
        string $groupId,
        int $defaultPerPage,
        ?int $suppliedPerPage,
        ?string $cursor,
        ?string $search = null,
    ): array {
        $eventId = trim((string) ($occurrence->event_id ?? ''));
        $occurrenceId = trim((string) $occurrence->getKey());
        if ($eventId === '' || $occurrenceId === '') {
            throw new NotFoundHttpException;
        }

        $group = $this->findOccurrenceGroupHeadOrFail($eventId, $occurrenceId, $groupId);
        $perPage = max(1, $defaultPerPage);
        $lastItemOrder = null;
        $lastRowId = null;

        if ($cursor !== null) {
            $payload = $this->decodeAdminCursor($cursor);
            if (($payload['scope'] ?? null) !== self::ADMIN_CURSOR_SCOPE
                || ($payload['tenant_id'] ?? null) !== $this->tenantId()
                || ($payload['event_id'] ?? null) !== $eventId
                || ($payload['occurrence_id'] ?? null) !== $occurrenceId
                || ($payload['group_id'] ?? null) !== (string) ($group['group_key'] ?? '')
                || ($payload['search'] ?? null) !== $search) {
                throw ValidationException::withMessages([
                    'cursor' => ['Event related-account member cursor is invalid for this occurrence or group.'],
                ]);
            }

            $cursorPerPage = (int) ($payload['per_page'] ?? 0);
            if ($suppliedPerPage !== null && $suppliedPerPage !== $cursorPerPage) {
                throw ValidationException::withMessages([
                    'per_page' => ['Event related-account member cursor fixes the page size for continuation requests.'],
                ]);
            }

            $perPage = max(1, $cursorPerPage);
            $lastItemOrder = (int) ($payload['last_item_order'] ?? -1);
            $lastRowId = trim((string) ($payload['last_row_id'] ?? ''));
        } elseif ($suppliedPerPage !== null) {
            $perPage = max(1, $suppliedPerPage);
        }

        $scope = [
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $occurrenceId,
            'group_key' => (string) ($group['group_key'] ?? ''),
            'doc_type' => self::DOC_TYPE_MEMBER,
        ];
        if ($search !== null) {
            $scope = $this->eventProfileResolver->memberSearchPredicate($scope, $search);
        }
        $constraints = [$scope];
        if ($lastItemOrder !== null && $lastRowId !== null) {
            $constraints[] = ['$or' => [
                ['item_order' => ['$gt' => $lastItemOrder]],
                ['item_order' => $lastItemOrder, '_id' => ['$gt' => $lastRowId]],
            ]];
        }
        $scope = count($constraints) === 1 ? $scope : ['$and' => $constraints];
        $pageRows = iterator_to_array($this->collection()->aggregate([
            ['$match' => $scope],
            ['$sort' => ['item_order' => 1, '_id' => 1]],
            ['$limit' => $perPage + 1],
            ['$lookup' => [
                'from' => 'account_profiles',
                'let' => ['member_profile_id' => '$nested_profile.id'],
                'pipeline' => [
                    ['$match' => [
                        '$expr' => [
                            '$eq' => [
                                '$_id',
                                [
                                    '$convert' => [
                                        'input' => '$$member_profile_id',
                                        'to' => 'objectId',
                                        'onError' => null,
                                        'onNull' => null,
                                    ],
                                ],
                            ],
                        ],
                    ]],
                    ['$project' => ['_id' => 1, 'display_name' => 1]],
                ],
                'as' => 'profile',
            ]],
        ]));
        $pageIds = array_values(array_filter(array_map(function (mixed $row): string {
            $document = $this->documentToArray($row);

            return trim((string) (($this->normalizeArray($document['nested_profile'] ?? [])['id'] ?? null) ?: ''));
        }, $pageRows)));
        $visibleIds = array_slice($pageIds, 0, $perPage);
        $profilesById = [];
        foreach (array_slice($pageRows, 0, $perPage) as $row) {
            $document = $this->documentToArray($row);
            $nestedProfile = $this->normalizeArray($document['nested_profile'] ?? []);
            $profileId = trim((string) ($nestedProfile['id'] ?? ''));
            $profileRows = $this->normalizeArray($document['profile'] ?? []);
            $profile = $this->normalizeArray($profileRows[0] ?? []);
            if ($profileId !== '' && $profile !== []) {
                $profilesById[$profileId] = $profile;
            }
        }

        $nextCursor = null;
        if (count($pageIds) > $perPage && count($pageRows) >= $perPage) {
            $last = $this->documentToArray($pageRows[$perPage - 1]);
            $nextCursor = Crypt::encryptString(json_encode([
                'version' => self::CURSOR_VERSION,
                'scope' => self::ADMIN_CURSOR_SCOPE,
                'tenant_id' => $this->tenantId(),
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'group_id' => (string) ($group['group_key'] ?? ''),
                'per_page' => $perPage,
                'search' => $search,
                'last_item_order' => (int) ($last['item_order'] ?? -1),
                'last_row_id' => (string) ($last['_id'] ?? ''),
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'data' => array_values(array_map(function (string $profileId) use ($profilesById): array {
                $profile = $profilesById[$profileId] ?? null;

                return [
                    'id' => $profileId,
                    'display_name' => is_array($profile)
                        ? ($label = trim((string) ($profile['display_name'] ?? ''))) === '' ? null : $label
                        : null,
                    'is_queryable_candidate' => is_array($profile),
                    'is_contact_capable_candidate' => false,
                ];
            }, $visibleIds)),
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * @param  array<int, string>  $addIds
     * @param  array<int, string>  $removeIds
     */
    public function patchOccurrenceGroupMembersWithinContext(
        EventTransactionContext $context,
        EventOccurrence $occurrence,
        string $groupId,
        array $addIds,
        array $removeIds,
    ): void {
        $eventId = trim((string) ($occurrence->event_id ?? ''));
        $occurrenceId = trim((string) $occurrence->getKey());
        if ($eventId === '' || $occurrenceId === '') {
            throw new NotFoundHttpException;
        }
        $group = $this->findOccurrenceGroupHeadOrFail($eventId, $occurrenceId, $groupId, $context);
        $groupKey = (string) ($group['group_key'] ?? '');
        $removeIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $removeIds,
        ), static fn (string $id): bool => $id !== '')));
        if ($removeIds !== []) {
            $context->collection(self::COLLECTION)->deleteMany([
                '_id' => ['$in' => array_map(
                    fn (string $memberId): string => $this->memberId($occurrenceId, $groupKey, $memberId),
                    $removeIds,
                )],
            ], $context->rawOptions());
        }

        $addIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            $addIds,
        ), static fn (string $id): bool => $id !== '')));
        if ($addIds === []) {
            return;
        }
        $profilesById = $this->profilesByIdForIds($addIds);
        $lastMember = $this->documentToArray($context->collection(self::COLLECTION)->findOne([
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $occurrenceId,
            'group_key' => $groupKey,
            'doc_type' => self::DOC_TYPE_MEMBER,
        ], [
            ...$context->rawOptions(),
            'projection' => ['item_order' => 1],
            'sort' => ['item_order' => -1, '_id' => -1],
        ]));
        $baseOrder = $lastMember === null ? 0 : ((int) ($lastMember['item_order'] ?? -1) + 1);
        $now = new UTCDateTime((int) now()->getTimestampMs());
        foreach ($addIds as $offset => $memberId) {
            $context->collection(self::COLLECTION)->updateOne(
                ['_id' => $this->memberId($occurrenceId, $groupKey, $memberId)],
                ['$setOnInsert' => [
                    'tenant_id' => $this->tenantId(),
                    'event_id' => $eventId,
                    'parent_type' => self::PARENT_TYPE,
                    'parent_id' => $occurrenceId,
                    'group_key' => $groupKey,
                    'item_order' => $baseOrder + $offset,
                    'doc_type' => self::DOC_TYPE_MEMBER,
                    'nested_profile' => $this->nestedProfileDocument(
                        $memberId,
                        $profilesById[$memberId] ?? null,
                    ),
                    'updated_at' => $now,
                ]],
                [...$context->rawOptions(), 'upsert' => true],
            );
        }
    }

    /**
     * Return the only related-account data needed by event cards: a count and
     * the first non-venue member per occurrence. Public reads apply profile and
     * account publication eligibility inside MongoDB before counting; management
     * reads retain every non-venue member. Member rows remain lazy and are never
     * expanded into the Event/Occurrence transport.
     *
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @return array<string, array{first_profile_id: ?string, first_profile: array<string, mixed>|null, counterpart_count: int}>
     */
    public function publicCounterpartSummariesByOccurrence(
        iterable $occurrences,
        bool $publicOnly = true,
    ): array {
        $occurrenceOrderById = $this->occurrenceOrderById($occurrences);
        if ($occurrenceOrderById === []) {
            return [];
        }

        $profileLookupPipeline = [
            ['$match' => ['$expr' => ['$eq' => [
                '$_id',
                ['$convert' => [
                    'input' => '$$member_profile_id',
                    'to' => 'objectId',
                    'onError' => null,
                    'onNull' => null,
                ]],
            ]]]],
            ['$match' => ['profile_type' => ['$ne' => 'venue']]],
        ];
        if ($publicOnly) {
            $profileLookupPipeline[] = [
                '$match' => $this->eventProfileResolver->publicMemberProfileMatchExpression(),
            ];
        }
        $profileLookupPipeline[] = ['$project' => [
            '_id' => 1,
            'account_id' => 1,
            'display_name' => 1,
            'profile_type' => 1,
            'slug' => 1,
            'avatar_url' => 1,
            'cover_url' => 1,
            'taxonomy_terms' => 1,
        ]];

        $pipeline = [
            ['$match' => [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'doc_type' => self::DOC_TYPE_MEMBER,
                'parent_id' => ['$in' => array_keys($occurrenceOrderById)],
                'nested_profile.id' => ['$nin' => [null, '']],
            ]],
            ['$lookup' => [
                'from' => self::COLLECTION,
                'let' => ['member_parent_id' => '$parent_id', 'member_group_key' => '$group_key'],
                'pipeline' => [
                    ['$match' => ['$expr' => ['$and' => [
                        ['$eq' => ['$tenant_id', $this->tenantId()]],
                        ['$eq' => ['$parent_type', self::PARENT_TYPE]],
                        ['$eq' => ['$doc_type', self::DOC_TYPE_HEAD]],
                        ['$eq' => ['$parent_id', '$$member_parent_id']],
                        ['$eq' => ['$group_key', '$$member_group_key']],
                    ]]]],
                    ['$project' => ['group_order' => 1]],
                ],
                'as' => 'group_head',
            ]],
            ['$unwind' => '$group_head'],
            ['$lookup' => [
                'from' => 'account_profiles',
                'let' => ['member_profile_id' => '$nested_profile.id'],
                'pipeline' => $profileLookupPipeline,
                'as' => 'profile',
            ]],
            ['$unwind' => '$profile'],
        ];
        if ($publicOnly) {
            $pipeline[] = ['$lookup' => [
                'from' => 'accounts',
                'let' => ['profile_account_id' => '$profile.account_id'],
                'pipeline' => [
                    ['$match' => ['$expr' => ['$eq' => [
                        '$_id',
                        ['$convert' => [
                            'input' => '$$profile_account_id',
                            'to' => 'objectId',
                            'onError' => null,
                            'onNull' => null,
                        ]],
                    ]]]],
                    ['$match' => $this->eventProfileResolver->publicMemberAccountMatchExpression()],
                    ['$project' => ['_id' => 1]],
                ],
                'as' => 'published_account',
            ]];
            $pipeline[] = ['$match' => ['published_account.0' => ['$exists' => true]]];
        }
        array_push(
            $pipeline,
            ['$sort' => ['parent_id' => 1, 'group_head.group_order' => 1, 'item_order' => 1, '_id' => 1]],
            ['$group' => [
                '_id' => [
                    'parent_id' => '$parent_id',
                    'profile_id' => '$nested_profile.id',
                ],
                'group_order' => ['$first' => '$group_head.group_order'],
                'item_order' => ['$first' => '$item_order'],
                'row_id' => ['$first' => '$_id'],
                'profile' => ['$first' => '$profile'],
            ]],
            ['$sort' => [
                '_id.parent_id' => 1,
                'group_order' => 1,
                'item_order' => 1,
                'row_id' => 1,
            ]],
            ['$group' => [
                '_id' => '$_id.parent_id',
                'first_profile_id' => ['$first' => '$_id.profile_id'],
                'first_profile' => ['$first' => '$profile'],
                'counterpart_count' => ['$sum' => 1],
            ]],
        );

        $rows = $this->collection()->aggregate($pipeline);

        $summaries = [];
        foreach ($rows as $row) {
            $document = $this->documentToArray($row);
            $occurrenceId = trim((string) ($document['_id'] ?? ''));
            if ($occurrenceId === '' || ! array_key_exists($occurrenceId, $occurrenceOrderById)) {
                continue;
            }

            $profileId = trim((string) ($document['first_profile_id'] ?? ''));
            $firstProfile = $this->documentToArray($document['first_profile'] ?? null);
            if ($firstProfile !== [] && $profileId !== '') {
                $firstProfile['id'] = $profileId;
                unset($firstProfile['_id']);
            }

            $summaries[$occurrenceId] = [
                'first_profile_id' => $profileId === ''
                    ? null
                    : $profileId,
                'first_profile' => $firstProfile === [] ? null : $firstProfile,
                'counterpart_count' => max(0, (int) ($document['counterpart_count'] ?? 0)),
            ];
        }

        return $summaries;
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

        $occurrenceOrderById = $this->occurrenceOrderById($occurrences);
        $headRows = $this->headRowsForEvent($eventId, array_keys($occurrenceOrderById));
        if ($headRows === []) {
            return [];
        }

        $buckets = [];
        $tabIdByHead = [];
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
            $occurrenceOrder = $occurrenceOrderById[$parentId];
            $groupOrder = (int) ($document['group_order'] ?? 0);
            $headKey = $this->headKey($parentId, $groupKey);
            $tabIdByHead[$headKey] = $tabId;

            if (! isset($buckets[$tabId])) {
                $buckets[$tabId] = [
                    'id' => $tabId,
                    'label' => $label,
                    'normalized_label' => $normalizedLabel,
                    'first_occurrence_order' => $occurrenceOrder,
                    'group_order' => $groupOrder,
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

        }

        $memberCountsByTab = $this->mergedRelationshipCountsByTab($eventId, $tabIdByHead);

        $groups = array_values(array_filter(array_map(
            function (array $bucket) use ($eventRouteKey, $memberCountsByTab): ?array {
                $memberCount = (int) ($memberCountsByTab[(string) $bucket['id']] ?? 0);
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
     * @param  array<string, string>  $tabIdByHead
     * @return array<string, int>
     */
    private function mergedRelationshipCountsByTab(string $eventId, array $tabIdByHead): array
    {
        if ($tabIdByHead === []) {
            return [];
        }

        $headPredicatesByTab = [];
        foreach ($tabIdByHead as $headKey => $tabId) {
            [$parentId, $groupKey] = explode('::', $headKey, 2);
            $headPredicatesByTab[$tabId][] = ['$and' => [
                ['$eq' => ['$parent_id', $parentId]],
                ['$eq' => ['$group_key', $groupKey]],
            ]];
        }
        uksort($headPredicatesByTab, static function (string $left, string $right) use ($headPredicatesByTab): int {
            return count($headPredicatesByTab[$right]) <=> count($headPredicatesByTab[$left])
                ?: $left <=> $right;
        });

        $branches = [];
        foreach ($headPredicatesByTab as $tabId => $headPredicates) {
            $branches[] = [
                'case' => count($headPredicates) === 1
                    ? $headPredicates[0]
                    : ['$or' => $headPredicates],
                'then' => $tabId,
            ];
        }

        $rows = $this->collection()->aggregate([
            ['$match' => [
                'tenant_id' => $this->tenantId(),
                'event_id' => $eventId,
                'parent_type' => self::PARENT_TYPE,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ]],
            ['$set' => ['resolved_tab_id' => ['$switch' => [
                'branches' => $branches,
                'default' => null,
            ]]]],
            ['$match' => ['resolved_tab_id' => ['$ne' => null]]],
            ['$group' => ['_id' => [
                'tab_id' => '$resolved_tab_id',
                'member_id' => '$nested_profile.id',
            ]]],
            ['$group' => [
                '_id' => '$_id.tab_id',
                'member_count' => ['$sum' => 1],
            ]],
        ]);

        $counts = [];
        foreach ($rows as $row) {
            $document = $this->documentToArray($row);
            $tabId = trim((string) ($document['_id'] ?? ''));
            if ($tabId !== '') {
                $counts[$tabId] = max(0, (int) ($document['member_count'] ?? 0));
            }
        }

        return $counts;
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
        ?string $search = null,
    ): array {
        $eventId = trim((string) $event->getKey());
        if ($eventId === '') {
            throw new NotFoundHttpException;
        }

        $resolvedTabId = trim($tabId);
        if ($resolvedTabId === '') {
            throw new NotFoundHttpException;
        }

        $perPage = max(1, $defaultPerPage);
        $lastBackingOrder = null;
        $lastItemOrder = null;
        $lastRowId = null;
        if ($cursor !== null) {
            $payload = $this->decodeCursor($cursor);
            if (($payload['scope'] ?? null) !== self::CURSOR_SCOPE
                || ($payload['tenant_id'] ?? null) !== $this->tenantId()
                || ($payload['event_id'] ?? null) !== $eventId
                || ($payload['tab_id'] ?? null) !== $resolvedTabId
                || ($payload['search'] ?? null) !== $search) {
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
            $lastBackingOrder = (int) ($payload['last_backing_order'] ?? -1);
            $lastItemOrder = (int) ($payload['last_item_order'] ?? -1);
            $lastRowId = trim((string) ($payload['last_row_id'] ?? ''));
        } elseif ($suppliedPerPage !== null) {
            $perPage = max(1, $suppliedPerPage);
        }

        $backings = $this->tabBackingsForEvent($eventId, $occurrences, $resolvedTabId);
        if ($backings === []) {
            throw new NotFoundHttpException;
        }

        $pairs = [];
        $orderBranches = [];
        foreach (array_values($backings) as $backingOrder => $backing) {
            $pair = [
                'parent_id' => (string) $backing['parent_id'],
                'group_key' => (string) $backing['group_key'],
            ];
            $pairs[] = $pair;
            $orderBranches[] = [
                'case' => ['$and' => [
                    ['$eq' => ['$parent_id', $pair['parent_id']]],
                    ['$eq' => ['$group_key', $pair['group_key']]],
                ]],
                'then' => $backingOrder,
            ];
        }
        $publiclyNavigableTypes = $this->eventProfileResolver->publiclyNavigableProfileTypes();
        $memberScope = [
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
            'doc_type' => self::DOC_TYPE_MEMBER,
            '$or' => $pairs,
        ];
        if ($search !== null) {
            $memberScope = $this->eventProfileResolver->memberSearchPredicate($memberScope, $search);
        }
        $pipeline = [
            ['$match' => $memberScope],
            ['$set' => ['_backing_order' => ['$switch' => [
                'branches' => $orderBranches,
                'default' => PHP_INT_MAX,
            ]]]],
            ['$sort' => ['_backing_order' => 1, 'item_order' => 1, '_id' => 1]],
            ['$group' => ['_id' => '$nested_profile.id', 'member' => ['$first' => '$$ROOT']]],
            ['$replaceRoot' => ['newRoot' => '$member']],
            ...($lastBackingOrder === null || $lastItemOrder === null || $lastRowId === null ? [] : [[
                '$match' => ['$or' => [
                    ['_backing_order' => ['$gt' => $lastBackingOrder]],
                    [
                        '_backing_order' => $lastBackingOrder,
                        'item_order' => ['$gt' => $lastItemOrder],
                    ],
                    [
                        '_backing_order' => $lastBackingOrder,
                        'item_order' => $lastItemOrder,
                        '_id' => ['$gt' => $lastRowId],
                    ],
                ]],
            ]]),
            ['$lookup' => [
                'from' => 'account_profiles',
                'let' => ['member_profile_id' => '$nested_profile.id'],
                'pipeline' => [
                    ['$match' => ['$expr' => ['$eq' => [
                        '$_id',
                        ['$convert' => [
                            'input' => '$$member_profile_id',
                            'to' => 'objectId',
                            'onError' => null,
                            'onNull' => null,
                        ]],
                    ]]]],
                    ['$match' => $this->eventProfileResolver->publicMemberProfileMatchExpression()],
                ],
                'as' => 'profile',
            ]],
            ['$unwind' => '$profile'],
            ['$lookup' => [
                'from' => 'accounts',
                'let' => ['profile_account_id' => '$profile.account_id'],
                'pipeline' => [
                    ['$match' => ['$expr' => ['$eq' => [
                        '$_id',
                        ['$convert' => [
                            'input' => '$$profile_account_id',
                            'to' => 'objectId',
                            'onError' => null,
                            'onNull' => null,
                        ]],
                    ]]]],
                    ['$match' => $this->eventProfileResolver->publicMemberAccountMatchExpression()],
                    ['$project' => ['_id' => 1]],
                ],
                'as' => 'published_account',
            ]],
            ['$match' => ['published_account.0' => ['$exists' => true]]],
            ['$set' => ['profile._can_open_public_detail' => ['$and' => [
                ['$eq' => ['$profile.is_active', true]],
                ['$eq' => ['$profile.visibility', 'public']],
                ['$in' => ['$profile.profile_type', $publiclyNavigableTypes]],
            ]]]],
            ['$sort' => ['_backing_order' => 1, 'item_order' => 1, '_id' => 1]],
            ['$limit' => $perPage + 1],
            ['$project' => ['profile' => 1, '_backing_order' => 1, 'item_order' => 1]],
        ];
        $pageDocuments = array_values(array_filter(array_map(
            fn (mixed $row): ?array => $this->documentToArray($row) ?: null,
            iterator_to_array($this->collection()->aggregate($pipeline)),
        )));
        $visibleDocuments = array_slice($pageDocuments, 0, $perPage);
        $visibleRows = array_values(array_filter(array_map(function (array $document): ?array {
            $profile = $this->normalizeArray($document['profile'] ?? []);

            return $profile === [] ? null : $profile;
        }, $visibleDocuments)));

        $nextCursor = null;
        if (count($pageDocuments) > $perPage && $visibleDocuments !== []) {
            $last = $visibleDocuments[array_key_last($visibleDocuments)];
            $nextCursor = Crypt::encryptString(json_encode([
                'version' => self::CURSOR_VERSION,
                'scope' => self::CURSOR_SCOPE,
                'tenant_id' => $this->tenantId(),
                'event_id' => $eventId,
                'tab_id' => $resolvedTabId,
                'per_page' => $perPage,
                'search' => $search,
                'last_backing_order' => (int) ($last['_backing_order'] ?? -1),
                'last_item_order' => (int) ($last['item_order'] ?? -1),
                'last_row_id' => (string) ($last['_id'] ?? ''),
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
     * @return array<string, mixed>
     */
    private function findOccurrenceGroupHeadOrFail(
        string $eventId,
        string $occurrenceId,
        string $groupId,
        ?EventTransactionContext $context = null,
    ): array {
        $groupId = trim($groupId);
        if ($groupId === '') {
            throw new NotFoundHttpException;
        }

        $filter = [
            'tenant_id' => $this->tenantId(),
            'event_id' => $eventId,
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $occurrenceId,
            'group_key' => $groupId,
            'doc_type' => self::DOC_TYPE_HEAD,
        ];
        $row = $context === null
            ? iterator_to_array($this->collection()->aggregate([
                ['$match' => $filter],
                ['$limit' => 1],
            ]))[0] ?? null
            : $context->collection(self::COLLECTION)->findOne($filter, $context->rawOptions());

        $document = $this->documentToArray($row);
        if ($document === []) {
            throw new NotFoundHttpException;
        }

        return $document;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function headRowsForEvent(string $eventId, array $occurrenceIds): array
    {
        $occurrenceIds = $this->normalizedStrings($occurrenceIds);
        if ($occurrenceIds === []) {
            return [];
        }

        return array_values(iterator_to_array($this->collection()->aggregate([
            ['$match' => [
                'tenant_id' => $this->tenantId(),
                'event_id' => $eventId,
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => ['$in' => $occurrenceIds],
                'doc_type' => self::DOC_TYPE_HEAD,
            ]],
            ['$sort' => ['group_order' => 1, '_id' => 1]],
        ])));
    }

    /**
     * @param  iterable<int, EventOccurrence>  $occurrences
     * @return array<int, array{parent_id:string,group_key:string,group_order:int,occurrence_order:int}>
     */
    private function tabBackingsForEvent(string $eventId, iterable $occurrences, string $tabId): array
    {
        $occurrenceOrderById = $this->occurrenceOrderById($occurrences);
        $backings = [];

        foreach ($this->headRowsForEvent($eventId, array_keys($occurrenceOrderById)) as $row) {
            $document = $this->documentToArray($row);
            $parentId = trim((string) ($document['parent_id'] ?? ''));
            $groupKey = trim((string) ($document['group_key'] ?? ''));
            $label = trim((string) ($document['group_label'] ?? ''));
            if ($parentId === '' || $groupKey === '' || $label === '') {
                continue;
            }

            $normalizedLabel = $this->normalizedLabel($label);
            if ($normalizedLabel === '' || $this->tabId($normalizedLabel) !== $tabId) {
                continue;
            }

            $backings[] = [
                'parent_id' => $parentId,
                'group_key' => $groupKey,
                'group_order' => (int) ($document['group_order'] ?? 0),
                'occurrence_order' => $occurrenceOrderById[$parentId],
            ];
        }

        usort(
            $backings,
            static fn (array $left, array $right): int => [
                (int) $left['occurrence_order'],
                (int) $left['group_order'],
                (string) $left['parent_id'],
                (string) $left['group_key'],
            ] <=> [
                (int) $right['occurrence_order'],
                (int) $right['group_order'],
                (string) $right['parent_id'],
                (string) $right['group_key'],
            ],
        );

        return $backings;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPublicMemberRow(array $row): array
    {
        $profile = $this->normalizeArray($row);
        $slug = trim((string) ($profile['slug'] ?? ''));
        $profileId = trim((string) (($profile['id'] ?? null) ?: ($profile['_id'] ?? '')));
        $canOpenPublicDetail = ($profile['_can_open_public_detail'] ?? false) === true && $slug !== '';

        return [
            'id' => $profileId,
            'display_name' => trim((string) ($profile['display_name'] ?? '')),
            'profile_type' => trim((string) ($profile['profile_type'] ?? '')),
            'slug' => $slug === '' ? null : $slug,
            'avatar_url' => is_string($profile['avatar_url'] ?? null) ? $profile['avatar_url'] : null,
            'cover_url' => is_string($profile['cover_url'] ?? null) ? $profile['cover_url'] : null,
            'taxonomy_terms' => is_array($profile['taxonomy_terms'] ?? null) ? array_values($profile['taxonomy_terms']) : [],
            'can_open_public_detail' => $canOpenPublicDetail,
            'public_detail_path' => $canOpenPublicDetail ? '/parceiro/'.$slug : null,
        ];
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
        $searchKey = trim((string) ($profile['search_key'] ?? ''));
        $searchTerms = array_values(array_unique(array_filter(array_map(
            static fn (mixed $term): string => trim((string) $term),
            (array) ($profile['search_terms'] ?? []),
        ), static fn (string $term): bool => $term !== '')));
        $document = [
            'id' => $memberProfileId,
        ];
        if ($searchKey !== '') {
            $document['search_key'] = $searchKey;
        }
        if ($searchTerms !== []) {
            $document['search_terms'] = $searchTerms;
        }

        return $document;
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
    private function decodeAdminCursor(string $cursor): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'cursor' => ['Event related-account member cursor is invalid.'],
            ]);
        }

        if (! is_array($payload) || (int) ($payload['version'] ?? 0) !== self::CURSOR_VERSION) {
            throw ValidationException::withMessages([
                'cursor' => ['Event related-account member cursor is invalid.'],
            ]);
        }

        $expiresAt = $payload['expires_at'] ?? null;
        if (! is_string($expiresAt) || Carbon::parse($expiresAt)->isPast()) {
            throw ValidationException::withMessages([
                'cursor' => ['Event related-account member cursor expired.'],
            ]);
        }

        return $payload;
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

    /** @param array<int, mixed> $values @return array<int, string> */
    private function normalizedStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                $normalized[$candidate] = $candidate;
            }
        }

        return array_values($normalized);
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
