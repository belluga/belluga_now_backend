<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Accounts\AccountPublicationStateService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Connection;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use MongoDB\Operation\FindOneAndUpdate;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AccountProfileNestedGroupMemberStore
{
    public const COLLECTION = 'accounts_nested';

    public const PARENT_TYPE = 'account_profile';

    private const DOC_TYPE_HEAD = 'group_head';

    private const DOC_TYPE_MEMBER = 'member_row';

    private const ADMIN_CURSOR_VERSION = 2;

    private const ADMIN_CURSOR_SCOPE = 'admin_nested_group_members';

    private const PUBLIC_CURSOR_VERSION = 2;

    private const PUBLIC_CURSOR_SCOPE = 'public_nested_members';

    public function __construct(
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileNestedGroupService $nestedGroupService,
        private readonly AccountProfilePublicCatalogSnapshotReader $publicCatalogSnapshotReader,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function metadataGroups(AccountProfile $profile): array
    {
        $groups = $this->metadataGroupsFromCollection((string) $profile->getKey());
        if ($groups === [] && $this->nestedGroupService->formatMetadataForRead($profile->nested_profile_groups ?? []) !== []) {
            throw new NotFoundHttpException;
        }

        return $groups;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function metadataGroupsWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
    ): array {
        $groups = $this->metadataGroupsFromCollection((string) $profile->getKey(), $context);
        if ($groups === [] && $this->nestedGroupService->formatMetadataForRead($profile->nested_profile_groups ?? []) !== []) {
            throw new NotFoundHttpException;
        }

        return $groups;
    }

    public function assertCanonicalGroupHeadsAvailableWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
    ): void {
        if ($this->nestedGroupService->formatMetadataForRead($profile->nested_profile_groups ?? []) === []) {
            return;
        }

        $parentProfileId = trim((string) $profile->getKey());
        if ($parentProfileId === '') {
            throw new NotFoundHttpException;
        }

        $head = $context->collection(self::COLLECTION)->findOne(
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $parentProfileId,
                'doc_type' => self::DOC_TYPE_HEAD,
            ],
            $context->rawOptions(),
        );
        if ($head === null) {
            throw new NotFoundHttpException;
        }
    }

    /** @return array<int, array{id:string,order:int}> */
    public function orderedGroupPositionsWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
    ): array {
        $parentProfileId = trim((string) $profile->getKey());
        if ($parentProfileId === '') {
            throw new NotFoundHttpException;
        }

        $rows = $context->collection(self::COLLECTION)->find([
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'doc_type' => self::DOC_TYPE_HEAD,
        ], [
            ...$context->rawOptions(),
            'projection' => ['group_key' => 1, 'group_order' => 1],
            'sort' => ['group_order' => 1, '_id' => 1],
        ]);

        return array_values(array_map(function (array|object $row): array {
            $document = $this->documentToArray($row) ?? [];

            return [
                'id' => trim((string) ($document['group_key'] ?? '')),
                'order' => (int) ($document['group_order'] ?? -1),
            ];
        }, iterator_to_array($rows)));
    }

    public function swapAdjacentGroupOrdersWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        array $moved,
        array $neighbor,
    ): void {
        $parentProfileId = trim((string) $profile->getKey());
        $movedId = trim((string) ($moved['id'] ?? ''));
        $neighborId = trim((string) ($neighbor['id'] ?? ''));
        if ($parentProfileId === '' || $movedId === '' || $neighborId === '') {
            throw new NotFoundHttpException;
        }

        $result = $context->collection(self::COLLECTION)->updateMany([
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
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
            throw new RuntimeException('Account Profile nested group heads changed during reorder.');
        }
    }

    /** @return array{id:string,label:string,_changed:bool} */
    public function renameGroupLabelWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $groupId,
        string $label,
    ): array {
        $parentProfileId = trim((string) $profile->getKey());
        $groupId = trim($groupId);
        $label = trim($label);
        if ($parentProfileId === '' || $groupId === '') {
            throw new NotFoundHttpException;
        }

        $filter = [
            '_id' => $this->headId($parentProfileId, $groupId),
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'group_key' => $groupId,
            'doc_type' => self::DOC_TYPE_HEAD,
        ];
        $result = $context->collection(self::COLLECTION)->updateOne(
            $filter,
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

    public function deleteGroupWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $groupId,
    ): void {
        $parentProfileId = trim((string) $profile->getKey());
        $groupId = trim($groupId);
        if ($parentProfileId === '' || $groupId === '') {
            throw new NotFoundHttpException;
        }

        $filter = [
            '_id' => $this->headId($parentProfileId, $groupId),
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'group_key' => $groupId,
            'doc_type' => self::DOC_TYPE_HEAD,
        ];
        $head = $this->documentToArray($context->collection(self::COLLECTION)->findOne($filter, $context->rawOptions()));
        if ($head === null) {
            throw new NotFoundHttpException;
        }
        $context->collection(self::COLLECTION)->deleteMany([
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'group_key' => $groupId,
        ], $context->rawOptions());
        $context->collection(self::COLLECTION)->updateMany([
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'doc_type' => self::DOC_TYPE_HEAD,
            'group_order' => ['$gt' => (int) ($head['group_order'] ?? 0)],
        ], ['$inc' => ['group_order' => -1]], $context->rawOptions());
    }

    /**
     * @return array{data: array<int, array<string, mixed>>,next_cursor:?string}
     */
    public function adminMemberPage(
        AccountProfile $parentProfile,
        string $groupId,
        int $defaultPerPage,
        ?int $suppliedPerPage,
        ?string $cursor,
        ?string $search,
        AccountProfileCandidateDiscoveryService $candidateDiscoveryService,
    ): array {
        $parentProfileId = (string) $parentProfile->getKey();
        $group = $this->findGroupHeadOrFail($parentProfileId, $groupId);
        $perPage = $defaultPerPage;
        $lastItemOrder = null;
        $lastRowId = null;

        if ($cursor !== null) {
            $payload = $this->decodeAdminCursor($cursor);
            if (($payload['scope'] ?? null) !== self::ADMIN_CURSOR_SCOPE
                || ($payload['tenant_id'] ?? null) !== $this->tenantId()
                || ($payload['parent_profile_id'] ?? null) !== $parentProfileId
                || ($payload['group_id'] ?? null) !== (string) ($group['group_key'] ?? '')
                || ($payload['search'] ?? null) !== $search) {
                throw ValidationException::withMessages([
                    'cursor' => ['Nested profile member cursor is invalid for this parent or group.'],
                ]);
            }

            $cursorPerPage = (int) ($payload['per_page'] ?? 0);
            if ($suppliedPerPage !== null && $suppliedPerPage !== $cursorPerPage) {
                throw ValidationException::withMessages([
                    'per_page' => ['Nested profile member cursor fixes the page size for continuation requests.'],
                ]);
            }

            $perPage = $cursorPerPage;
            $lastItemOrder = (int) ($payload['last_item_order'] ?? -1);
            $lastRowId = trim((string) ($payload['last_row_id'] ?? ''));
        }

        $filter = [
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'group_key' => (string) ($group['group_key'] ?? ''),
            'doc_type' => self::DOC_TYPE_MEMBER,
        ];
        if ($search !== null) {
            $filter = AccountProfileSearchV1::mongoScopedOrPredicate(
                $filter,
                'nested_profile.search_key',
                'nested_profile.search_terms',
                $search,
            );
        }
        $constraints = [$filter];
        if ($lastItemOrder !== null && $lastRowId !== null) {
            $constraints[] = ['$or' => [
                ['item_order' => ['$gt' => $lastItemOrder]],
                ['item_order' => $lastItemOrder, '_id' => ['$gt' => $lastRowId]],
            ]];
        }
        $filter = count($constraints) === 1 ? $filter : ['$and' => $constraints];
        $rows = iterator_to_array($this->collection()->aggregate([
            ['$match' => $filter],
            ['$sort' => ['item_order' => 1, '_id' => 1]],
            ['$limit' => $perPage + 1],
            ...$this->adminProfileLookupStages(),
        ]));

        $normalizedRows = array_values(array_filter(array_map(function (array|object $row): ?array {
            $document = $this->documentToArray($row) ?? [];

            return trim((string) (($document['nested_profile']['id'] ?? null) ?: '')) === ''
                ? null
                : $document;
        }, $rows)));
        $pageRows = array_slice($normalizedRows, 0, $perPage);
        $pageIds = array_map(
            static fn (array $row): string => (string) $row['nested_profile']['id'],
            $pageRows,
        );
        $profileDocumentsById = [];
        foreach ($pageRows as $row) {
            $profileId = trim((string) data_get($row, 'nested_profile.id'));
            $profile = data_get($row, 'profile.0');
            if ($profileId !== '' && is_array($profile)) {
                $profileDocumentsById[$profileId] = $profile;
            }
        }
        $selectedSummaries = $candidateDiscoveryService->selectedSummariesFromDocuments(
            $pageIds,
            $profileDocumentsById,
        );
        $data = array_values(array_map(
            static fn (string $profileId): array => $selectedSummaries[$profileId] ?? [
                'id' => $profileId,
                'display_name' => null,
                'is_queryable_candidate' => false,
                'is_contact_capable_candidate' => false,
            ],
            $pageIds,
        ));

        $nextCursor = null;
        if (count($normalizedRows) > $perPage && $pageRows !== []) {
            $last = $pageRows[array_key_last($pageRows)];
            $nextCursor = Crypt::encryptString(json_encode([
                'version' => self::ADMIN_CURSOR_VERSION,
                'scope' => self::ADMIN_CURSOR_SCOPE,
                'tenant_id' => $this->tenantId(),
                'parent_profile_id' => $parentProfileId,
                'group_id' => (string) ($group['group_key'] ?? ''),
                'search' => $search,
                'per_page' => $perPage,
                'last_item_order' => (int) ($last['item_order'] ?? -1),
                'last_row_id' => (string) ($last['_id'] ?? ''),
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'data' => $data,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * @return array{data:array<int,array<string,mixed>>,next_cursor:?string}
     */
    public function publicMemberPage(
        AccountProfile $parentProfile,
        string $groupId,
        int $defaultPerPage,
        ?int $suppliedPerPage,
        ?string $cursor,
        ?string $search,
    ): array {
        $parentProfileId = trim((string) $parentProfile->getKey());
        $group = $this->findGroupHeadOrFail($parentProfileId, $groupId);
        $perPage = $defaultPerPage;
        $lastItemOrder = null;
        $lastRowId = null;

        if ($cursor !== null) {
            $payload = $this->decodePublicCursor($cursor);
            if (($payload['scope'] ?? null) !== self::PUBLIC_CURSOR_SCOPE
                || ($payload['tenant_id'] ?? null) !== $this->tenantId()
                || ($payload['parent_profile_id'] ?? null) !== $parentProfileId
                || ($payload['group_id'] ?? null) !== (string) ($group['group_key'] ?? '')
                || ($payload['search'] ?? null) !== $search
                || (int) ($payload['aggregate_revision'] ?? -1) !== (int) ($parentProfile->aggregate_revision ?? 0)) {
                throw ValidationException::withMessages([
                    'cursor' => ['Nested profile member cursor is invalid for this parent or group.'],
                ]);
            }
            $cursorPerPage = (int) ($payload['per_page'] ?? 0);
            if ($suppliedPerPage !== null && $suppliedPerPage !== $cursorPerPage) {
                throw ValidationException::withMessages([
                    'per_page' => ['Nested profile member cursor fixes the page size for continuation requests.'],
                ]);
            }
            $perPage = $cursorPerPage;
            $lastItemOrder = (int) ($payload['last_item_order'] ?? -1);
            $lastRowId = trim((string) ($payload['last_row_id'] ?? ''));
        }

        $scope = [
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'group_key' => (string) ($group['group_key'] ?? ''),
            'doc_type' => self::DOC_TYPE_MEMBER,
        ];
        if ($lastItemOrder !== null && $lastRowId !== null) {
            $scope['$or'] = [
                ['item_order' => ['$gt' => $lastItemOrder]],
                ['item_order' => $lastItemOrder, '_id' => ['$gt' => $lastRowId]],
            ];
        }

        $pipeline = $this->memberSearchCandidateStages($scope, $search);
        $pipeline[] = ['$sort' => ['item_order' => 1, '_id' => 1]];
        $pipeline = [
            ...$pipeline,
            ...$this->liveProfileLookupStages(publicOnly: true),
            ['$limit' => $perPage + 1],
        ];

        $rows = iterator_to_array($this->collection()->aggregate($pipeline));
        $normalizedRows = array_values(array_filter(array_map(
            fn (mixed $row): ?array => $this->documentToArray($row),
            $rows,
        )));
        $pageRows = array_slice($normalizedRows, 0, $perPage);
        if ($pageRows === [] && $cursor === null && $search === null) {
            throw new NotFoundHttpException;
        }

        $nextCursor = null;
        if (count($normalizedRows) > $perPage && $pageRows !== []) {
            $last = $pageRows[array_key_last($pageRows)];
            $nextCursor = Crypt::encryptString(json_encode([
                'version' => self::PUBLIC_CURSOR_VERSION,
                'scope' => self::PUBLIC_CURSOR_SCOPE,
                'tenant_id' => $this->tenantId(),
                'parent_profile_id' => $parentProfileId,
                'group_id' => (string) ($group['group_key'] ?? ''),
                'aggregate_revision' => (int) ($parentProfile->aggregate_revision ?? 0),
                'per_page' => $perPage,
                'search' => $search,
                'last_item_order' => (int) ($last['item_order'] ?? -1),
                'last_row_id' => (string) ($last['_id'] ?? ''),
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'data' => array_values(array_map(
                fn (array $row): array => $this->formatPublicProfile((array) ($row['profile'] ?? [])),
                $pageRows,
            )),
            'next_cursor' => $nextCursor,
        ];
    }

    /** @return array<int,array{id:string,label:string,order:int,member_count:int,members_path:string}> */
    public function publicMetadataGroups(AccountProfile $profile): array
    {
        $groups = $this->metadataGroups($profile);
        if ($groups === []) {
            return [];
        }

        $slug = trim((string) $profile->slug);

        return array_values(array_filter(array_map(
            static function (array $group) use ($slug): ?array {
                $count = max(0, (int) ($group['member_count'] ?? 0));
                if ($count === 0) {
                    return null;
                }

                return [
                    'id' => (string) $group['id'],
                    'label' => (string) $group['label'],
                    'order' => (int) $group['order'],
                    'member_count' => $count,
                    'members_path' => "/api/v1/account_profiles/{$slug}/nested_profile_groups/{$group['id']}/members",
                ];
            },
            $groups,
        )));
    }

    /**
     * Refreshes only Account-parent search access derivatives. Event rows are
     * deliberately insertion-time snapshots and never match this predicate.
     *
     * @param  array<int, mixed>  $searchTerms
     */
    public function refreshSearchForMemberWithinContext(
        AccountProfileTransactionContext $context,
        string $memberProfileId,
        string $searchKey,
        array $searchTerms,
    ): void {
        $memberProfileId = trim($memberProfileId);
        if ($memberProfileId === '') {
            return;
        }

        $set = ['nested_profile.search_key' => trim($searchKey)];
        $unset = [];
        $normalizedTerms = $this->normalizedStrings($searchTerms);
        if ($normalizedTerms === []) {
            $unset['nested_profile.search_terms'] = true;
        } else {
            $set['nested_profile.search_terms'] = $normalizedTerms;
        }

        $update = ['$set' => $set];
        if ($unset !== []) {
            $update['$unset'] = $unset;
        }

        $context->collection(self::COLLECTION)->updateMany(
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'doc_type' => self::DOC_TYPE_MEMBER,
                'nested_profile.id' => $memberProfileId,
            ],
            $update,
            $context->rawOptions(),
        );
    }

    /**
     * Applies a bounded relationship delta without materializing or rewriting
     * preserved members. Account additions acquire the Profile document lock
     * before capturing its canonical search fields.
     *
     * @param  array<int, string>  $addIds
     * @param  array<int, string>  $removeIds
     */
    public function patchGroupMembersWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $groupId,
        array $addIds,
        array $removeIds,
    ): void {
        $parentProfileId = trim((string) $profile->getKey());
        $group = $this->documentToArray($context->collection(self::COLLECTION)->findOne([
            '_id' => $this->headId($parentProfileId, $groupId),
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'group_key' => $groupId,
            'doc_type' => self::DOC_TYPE_HEAD,
        ], $context->rawOptions()));
        if ($group === null) {
            throw new NotFoundHttpException;
        }

        $removeIds = $this->normalizedStrings($removeIds);
        if ($removeIds !== []) {
            $context->collection(self::COLLECTION)->deleteMany([
                '_id' => ['$in' => array_map(
                    fn (string $memberId): string => $this->memberId($parentProfileId, $groupId, $memberId),
                    $removeIds,
                )],
            ], $context->rawOptions());
        }

        $addIds = $this->normalizedStrings($addIds);
        if ($addIds === []) {
            return;
        }

        $lastMember = $this->documentToArray($context->collection(self::COLLECTION)->findOne([
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'group_key' => $groupId,
            'doc_type' => self::DOC_TYPE_MEMBER,
        ], [
            ...$context->rawOptions(),
            'projection' => ['item_order' => 1],
            'sort' => ['item_order' => -1, '_id' => -1],
        ]));
        $baseOrder = $lastMember === null ? 0 : ((int) ($lastMember['item_order'] ?? -1) + 1);
        $now = new UTCDateTime((int) now()->getTimestampMs());
        foreach ($addIds as $offset => $memberId) {
            $locked = $context->collection('account_profiles')->findOneAndUpdate(
                ['_id' => new ObjectId($memberId), 'deleted_at' => null],
                ['$set' => ['search_consistency_lock' => new ObjectId]],
                [...$context->rawOptions(), 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
            );
            $lockedProfile = $this->documentToArray($locked);
            if ($lockedProfile === null) {
                throw ValidationException::withMessages([
                    'add_ids' => ["Account Profile [{$memberId}] is unavailable."],
                ]);
            }

            $nestedProfile = ['id' => $memberId];
            $searchKey = trim((string) ($lockedProfile['name_search_key'] ?? ''));
            if ($searchKey !== '') {
                $nestedProfile['search_key'] = $searchKey;
            }
            $searchTerms = $this->normalizedStrings((array) ($lockedProfile['search_terms'] ?? []));
            if ($searchTerms !== []) {
                $nestedProfile['search_terms'] = $searchTerms;
            }

            $context->collection(self::COLLECTION)->updateOne(
                ['_id' => $this->memberId($parentProfileId, $groupId, $memberId)],
                ['$setOnInsert' => [
                    'tenant_id' => $this->tenantId(),
                    'parent_type' => self::PARENT_TYPE,
                    'parent_id' => $parentProfileId,
                    'group_key' => $groupId,
                    'item_order' => $baseOrder + $offset,
                    'doc_type' => self::DOC_TYPE_MEMBER,
                    'nested_profile' => $nestedProfile,
                    'updated_at' => $now,
                ]],
                [...$context->rawOptions(), 'upsert' => true],
            );
        }
    }

    /** @param array<int, string> $memberProfileIds */
    public function removeMemberIdsFromParentWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        array $memberProfileIds,
    ): int {
        $memberProfileIds = $this->normalizedStrings($memberProfileIds);
        if ($memberProfileIds === []) {
            return 0;
        }

        $result = $context->collection(self::COLLECTION)->deleteMany([
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => (string) $profile->getKey(),
            'doc_type' => self::DOC_TYPE_MEMBER,
            'nested_profile.id' => ['$in' => $memberProfileIds],
        ], $context->rawOptions());

        return $result->getDeletedCount();
    }

    /**
     * @param  array<int, string>  $memberProfileIds
     * @return array<int, string>
     */
    public function parentProfileIdsForMemberIdsWithinContext(
        AccountProfileTransactionContext $context,
        array $memberProfileIds,
    ): array {
        $normalizedProfileIds = $this->normalizedStrings($memberProfileIds);
        if ($normalizedProfileIds === []) {
            return [];
        }

        return $this->normalizedStrings(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $context->collection(self::COLLECTION)->distinct(
                'parent_id',
                [
                    'tenant_id' => $this->tenantId(),
                    'parent_type' => self::PARENT_TYPE,
                    'doc_type' => self::DOC_TYPE_MEMBER,
                    'nested_profile.id' => ['$in' => $normalizedProfileIds],
                ],
                $context->rawOptions(),
            ),
        ));
    }

    /** @param array<int, array<string, mixed>> $groups */
    public function synchronizeGroupHeadsWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        array $groups,
    ): void {
        $tenantId = $this->tenantId();
        $parentProfileId = (string) $profile->getKey();
        $normalizedGroups = [];
        $groupIds = [];

        foreach ($groups as $rawGroup) {
            if (! is_array($rawGroup)) {
                continue;
            }

            $formattedGroup = $this->nestedGroupService->formatMetadataForRead([$rawGroup])[0] ?? null;
            if (! is_array($formattedGroup)) {
                continue;
            }

            $normalizedGroups[] = $formattedGroup;
        }

        foreach ($normalizedGroups as $group) {
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            $groupIds[] = $groupId;
        }

        if ($groupIds === []) {
            $context->collection(self::COLLECTION)->deleteMany(
                [
                    'tenant_id' => $tenantId,
                    'parent_type' => self::PARENT_TYPE,
                    'parent_id' => $parentProfileId,
                ],
                $context->rawOptions(),
            );

            return;
        }

        $now = new UTCDateTime((int) now()->getTimestampMs());

        foreach ($normalizedGroups as $group) {
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            $groupLabel = trim((string) ($group['label'] ?? ''));
            $groupOrder = (int) ($group['order'] ?? 0);

            $context->collection(self::COLLECTION)->updateOne(
                ['_id' => $this->headId($parentProfileId, $groupId)],
                [
                    '$set' => [
                        'tenant_id' => $tenantId,
                        'parent_type' => self::PARENT_TYPE,
                        'parent_id' => $parentProfileId,
                        'group_key' => $groupId,
                        'group_label' => $groupLabel,
                        'group_order' => $groupOrder,
                        'doc_type' => self::DOC_TYPE_HEAD,
                        'updated_at' => $now,
                    ],
                ],
                [...$context->rawOptions(), 'upsert' => true],
            );
        }

        $context->collection(self::COLLECTION)->deleteMany(
            [
                'tenant_id' => $tenantId,
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $parentProfileId,
                'group_key' => ['$nin' => array_values(array_unique($groupIds))],
            ],
            $context->rawOptions(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function findGroupHeadOrFail(string $parentProfileId, string $groupId): array
    {
        $row = $this->documentToArray($this->collection()->findOne([
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'group_key' => $groupId,
            'doc_type' => self::DOC_TYPE_HEAD,
        ]));

        if ($row === null) {
            throw new NotFoundHttpException;
        }

        return $row;
    }

    /**
     * @return array<string, int>
     */
    private function memberCountsByGroup(
        string $parentProfileId,
        ?AccountProfileTransactionContext $context = null,
    ): array {
        $collection = $context?->collection(self::COLLECTION) ?? $this->collection();
        $options = $context?->rawOptions() ?? [];
        $rows = $collection->aggregate([
            ['$match' => [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $parentProfileId,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ]],
            ['$group' => ['_id' => '$group_key', 'member_count' => ['$sum' => 1]]],
        ], $options);

        $counts = [];
        foreach ($rows as $row) {
            $document = $this->documentToArray($row) ?? [];
            $groupId = trim((string) ($document['_id'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            $counts[$groupId] = max(0, (int) ($document['member_count'] ?? 0));
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function metadataGroupsFromCollection(
        string $parentProfileId,
        ?AccountProfileTransactionContext $context = null,
    ): array {
        $collection = $context?->collection(self::COLLECTION) ?? $this->collection();
        $options = $context?->rawOptions() ?? [];
        $rows = iterator_to_array($collection->find(
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $parentProfileId,
                'doc_type' => self::DOC_TYPE_HEAD,
            ],
            [
                'sort' => ['group_order' => 1, '_id' => 1],
                ...$options,
            ],
        ));

        if ($rows === []) {
            return [];
        }

        $counts = $this->memberCountsByGroup($parentProfileId, $context);

        return array_values(array_map(function (array|object $row) use ($counts): array {
            $document = $this->documentToArray($row) ?? [];
            $groupId = trim((string) ($document['group_key'] ?? ''));

            return [
                'id' => $groupId,
                'label' => (string) ($document['group_label'] ?? ''),
                'order' => (int) ($document['group_order'] ?? 0),
                'member_count' => max(0, (int) ($counts[$groupId] ?? 0)),
            ];
        }, $rows));
    }

    private function headId(string $parentProfileId, string $groupId): string
    {
        return 'accounts-nested:head:'.self::PARENT_TYPE.':'.$parentProfileId.':'.$groupId;
    }

    private function memberId(string $parentProfileId, string $groupId, string $memberProfileId): string
    {
        return 'accounts-nested:member:'.self::PARENT_TYPE.':'.$parentProfileId.':'.$groupId.':'.$memberProfileId;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizedStrings(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate !== '' && ! isset($normalized[$candidate])) {
                $normalized[$candidate] = $candidate;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<int, array<string, mixed>>
     */
    private function memberSearchCandidateStages(array $scope, ?string $search): array
    {
        if ($search === null) {
            return [['$match' => $scope]];
        }

        return [['$match' => AccountProfileSearchV1::mongoScopedOrPredicate(
            $scope,
            'nested_profile.search_key',
            'nested_profile.search_terms',
            $search,
        )]];
    }

    /** @return array<int, array<string, mixed>> */
    private function liveProfileLookupStages(bool $publicOnly): array
    {
        $policy = $this->publicCatalogSnapshotReader->catalogSnapshot()->policy();
        $profilePipeline = [[
            '$match' => [
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
            ],
        ]];
        if ($publicOnly) {
            $profilePipeline[] = ['$match' => $policy->catalogMatchExpression()];
        }

        $stages = [
            ['$lookup' => [
                'from' => 'account_profiles',
                'let' => ['member_profile_id' => '$nested_profile.id'],
                'pipeline' => $profilePipeline,
                'as' => 'profile',
            ]],
            ['$unwind' => '$profile'],
        ];
        if (! $publicOnly) {
            return $stages;
        }

        return [
            ...$stages,
            ['$lookup' => [
                'from' => 'accounts',
                'let' => ['profile_account_id' => '$profile.account_id'],
                'pipeline' => [
                    ['$match' => [
                        '$expr' => [
                            '$eq' => [
                                '$_id',
                                [
                                    '$convert' => [
                                        'input' => '$$profile_account_id',
                                        'to' => 'objectId',
                                        'onError' => null,
                                        'onNull' => null,
                                    ],
                                ],
                            ],
                        ],
                    ]],
                    ['$match' => ['publication.status' => AccountPublicationStateService::PUBLISHED]],
                    ['$project' => ['_id' => 1]],
                ],
                'as' => 'published_account',
            ]],
            ['$match' => ['published_account.0' => ['$exists' => true]]],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function adminProfileLookupStages(): array
    {
        return [[
            '$lookup' => [
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
                    ['$project' => [
                        '_id' => 1,
                        'display_name' => 1,
                        'profile_type' => 1,
                        'is_active' => 1,
                        'visibility' => 1,
                        'contact_mode' => 1,
                        'deleted_at' => 1,
                    ]],
                ],
                'as' => 'profile',
            ],
        ]];
    }

    /** @param array<string, mixed> $profile */
    private function formatPublicProfile(array $profile): array
    {
        $slug = trim((string) ($profile['slug'] ?? ''));

        return [
            'id' => (string) ($profile['_id'] ?? ''),
            'profile_type' => (string) ($profile['profile_type'] ?? ''),
            'display_name' => (string) ($profile['display_name'] ?? ''),
            'slug' => $slug === '' ? null : $slug,
            'avatar_url' => is_string($profile['avatar_url'] ?? null) ? $profile['avatar_url'] : null,
            'cover_url' => is_string($profile['cover_url'] ?? null) ? $profile['cover_url'] : null,
            'taxonomy_terms' => is_array($profile['taxonomy_terms'] ?? null) ? $profile['taxonomy_terms'] : [],
            'can_open_public_detail' => $slug !== '',
            'public_detail_path' => $slug === '' ? null : '/parceiro/'.$slug,
        ];
    }

    private function tenantId(): string
    {
        $tenantId = trim((string) (Tenant::current()?->getKey() ?? ''));
        if ($tenantId === '') {
            throw new RuntimeException('Current tenant is required for nested group member storage.');
        }

        return $tenantId;
    }

    private function collection(): \MongoDB\Collection
    {
        $connection = DB::connection('tenant');
        if (! $connection instanceof Connection) {
            throw new RuntimeException('A MongoDB tenant connection is required for nested group member storage.');
        }

        return $connection->getDatabase()->selectCollection(self::COLLECTION);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAdminCursor(string $cursor): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'cursor' => ['Nested profile member cursor is invalid.'],
            ]);
        }

        if (! is_array($payload) || (int) ($payload['version'] ?? 0) !== self::ADMIN_CURSOR_VERSION) {
            throw ValidationException::withMessages([
                'cursor' => ['Nested profile member cursor is invalid.'],
            ]);
        }

        $expiresAt = $payload['expires_at'] ?? null;
        if (! is_string($expiresAt) || Carbon::parse($expiresAt)->isPast()) {
            throw ValidationException::withMessages([
                'cursor' => ['Nested profile member cursor expired.'],
            ]);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function decodePublicCursor(string $cursor): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'cursor' => ['Nested profile member cursor is invalid.'],
            ]);
        }

        if (! is_array($payload) || (int) ($payload['version'] ?? 0) !== self::PUBLIC_CURSOR_VERSION) {
            throw ValidationException::withMessages([
                'cursor' => ['Nested profile member cursor is invalid.'],
            ]);
        }
        $expiresAt = $payload['expires_at'] ?? null;
        if (! is_string($expiresAt) || Carbon::parse($expiresAt)->isPast()) {
            throw ValidationException::withMessages([
                'cursor' => ['Nested profile member cursor expired.'],
            ]);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentToArray(mixed $document): ?array
    {
        if ($document instanceof BSONDocument) {
            $document = $document->getArrayCopy();
        }
        if ($document instanceof BSONArray) {
            $document = $document->getArrayCopy();
        }

        if (! is_array($document)) {
            return null;
        }

        foreach ($document as $key => $value) {
            if ($value instanceof BSONDocument || $value instanceof BSONArray) {
                $document[$key] = $this->documentToArray($value);
            }
        }

        return $document;
    }
}
