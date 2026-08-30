<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Laravel\Connection;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AccountProfileNestedGroupMemberStore
{
    public const COLLECTION = 'accounts_nested';

    public const PARENT_TYPE = 'account_profile';

    private const DOC_TYPE_HEAD = 'group_head';

    private const DOC_TYPE_MEMBER = 'member_row';

    private const ADMIN_CURSOR_VERSION = 1;

    private const ADMIN_CURSOR_SCOPE = 'admin_nested_group_members';

    public function __construct(
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileNestedGroupService $nestedGroupService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function metadataGroups(AccountProfile $profile): array
    {
        $groups = $this->metadataGroupsFromCollection((string) $profile->getKey());
        if ($groups === [] && $this->nestedGroupService->formatForRead($profile->nested_profile_groups ?? []) !== []) {
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
        if ($groups === [] && $this->nestedGroupService->formatForRead($profile->nested_profile_groups ?? []) !== []) {
            throw new NotFoundHttpException;
        }

        return $groups;
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
        if ($context->collection(self::COLLECTION)->findOne($filter, $context->rawOptions()) === null) {
            throw new NotFoundHttpException;
        }
        $context->collection(self::COLLECTION)->deleteMany([
            'tenant_id' => $this->tenantId(),
            'parent_type' => self::PARENT_TYPE,
            'parent_id' => $parentProfileId,
            'group_key' => $groupId,
        ], $context->rawOptions());
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
        AccountProfileCandidateDiscoveryService $candidateDiscoveryService,
    ): array {
        $parentProfileId = (string) $parentProfile->getKey();
        $group = $this->findGroupHeadOrFail($parentProfileId, $groupId);
        $perPage = $defaultPerPage;
        $offset = 0;

        if ($cursor !== null) {
            $payload = $this->decodeAdminCursor($cursor);
            if (($payload['scope'] ?? null) !== self::ADMIN_CURSOR_SCOPE
                || ($payload['parent_profile_id'] ?? null) !== $parentProfileId
                || ($payload['group_id'] ?? null) !== (string) ($group['group_key'] ?? '')) {
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
            $offset = max(0, (int) ($payload['offset'] ?? 0));
        }

        $rows = iterator_to_array($this->collection()->find(
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $parentProfileId,
                'group_key' => (string) ($group['group_key'] ?? ''),
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                'sort' => ['item_order' => 1, '_id' => 1],
                'skip' => $offset,
                'limit' => $perPage + 1,
            ],
        ));

        $memberIds = array_values(array_filter(array_map(function (array|object $row): string {
            $document = $this->documentToArray($row) ?? [];

            return trim((string) (($document['nested_profile']['id'] ?? null) ?: ''));
        }, $rows)));

        $pageIds = array_slice($memberIds, 0, $perPage);
        $selectedSummaries = $candidateDiscoveryService->selectedSummariesByIds($pageIds);
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
        if (count($memberIds) > $perPage) {
            $nextCursor = Crypt::encryptString(json_encode([
                'version' => self::ADMIN_CURSOR_VERSION,
                'scope' => self::ADMIN_CURSOR_SCOPE,
                'parent_profile_id' => $parentProfileId,
                'group_id' => (string) ($group['group_key'] ?? ''),
                'per_page' => $perPage,
                'offset' => $offset + $perPage,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'data' => $data,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function groupMemberIds(AccountProfile $profile, string $groupId): array
    {
        return $this->transactionRunner->run(
            fn (AccountProfileTransactionContext $context): array => $this->groupMemberIdsWithinContext(
                $context,
                $profile,
                $groupId,
            )
        );
    }

    /**
     * @return array<int, string>
     */
    public function groupMemberIdsWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $groupId,
    ): array {
        $rows = iterator_to_array($context->collection(self::COLLECTION)->find(
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => (string) $profile->getKey(),
                'group_key' => $groupId,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                'sort' => ['item_order' => 1, '_id' => 1],
                ...$context->rawOptions(),
            ],
        ));

        return array_values(array_filter(array_map(function (array|object $row): string {
            $document = $this->documentToArray($row) ?? [];

            return trim((string) (($document['nested_profile']['id'] ?? null) ?: ''));
        }, $rows)));
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

        $projectedParentIds = $this->normalizedStrings(array_map(
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

        $embeddedParentIds = AccountProfile::withTrashed()
            ->whereIn('nested_profile_groups.account_profile_ids', $normalizedProfileIds)
            ->orderBy('_id')
            ->get(['_id'])
            ->map(static fn (AccountProfile $profile): string => trim((string) $profile->getKey()))
            ->filter(static fn (string $profileId): bool => $profileId !== '')
            ->values()
            ->all();

        return $this->normalizedStrings(array_merge($projectedParentIds, $embeddedParentIds));
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<string, AccountProfile>  $profilesById
     */
    public function replaceAllGroupsWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        array $groups,
        array $profilesById = [],
    ): void {
        $tenantId = $this->tenantId();
        $parentProfileId = (string) $profile->getKey();
        $normalizedGroups = [];
        $groupIds = [];
        $groupMemberIdsByGroupId = [];
        $groupsWithExplicitMembers = [];
        $allMemberIds = [];

        foreach ($groups as $rawGroup) {
            if (! is_array($rawGroup)) {
                continue;
            }

            $formattedGroup = $this->nestedGroupService->formatForRead([$rawGroup])[0] ?? null;
            if (! is_array($formattedGroup)) {
                continue;
            }

            $normalizedGroups[] = $formattedGroup;

            $groupId = trim((string) ($formattedGroup['id'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            $hasExplicitMembers = array_key_exists('account_profile_ids', $rawGroup)
                || array_key_exists('profile_ids', $rawGroup);
            if (! $hasExplicitMembers) {
                continue;
            }

            $groupsWithExplicitMembers[$groupId] = true;
            $groupMemberIdsByGroupId[$groupId] = [];
            foreach ((array) ($formattedGroup['account_profile_ids'] ?? []) as $memberId) {
                $memberId = trim((string) $memberId);
                if ($memberId === '') {
                    continue;
                }

                $groupMemberIdsByGroupId[$groupId][] = $memberId;
                $allMemberIds[$memberId] = $memberId;
            }
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

        $profilesById = $this->profilesByIdForIds(array_values($allMemberIds), $profilesById);
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

            if (isset($groupsWithExplicitMembers[$groupId])) {
                $this->replaceGroupMembersWithinContext(
                    $context,
                    $profile,
                    $groupId,
                    array_values($groupMemberIdsByGroupId[$groupId] ?? []),
                    $profilesById,
                    $group,
                );
            } else {
                $this->syncGroupMemberMetadataWithinContext(
                    $context,
                    $profile,
                    $groupId,
                    $groupLabel,
                    $groupOrder,
                );
            }
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
     * @param  array<int, string>  $memberIds
     * @param  array<string, AccountProfile>  $profilesById
     * @param  array<string, mixed>|null  $group
     */
    public function replaceGroupMembersWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $groupId,
        array $memberIds,
        array $profilesById = [],
        ?array $group = null,
    ): void {
        $tenantId = $this->tenantId();
        $parentProfileId = (string) $profile->getKey();
        $groupId = trim($groupId);
        if ($groupId === '') {
            return;
        }

        $profilesById = $this->profilesByIdForIds($memberIds, $profilesById);
        $groupLabel = trim((string) ($group['label'] ?? ''));
        $groupOrder = (int) ($group['order'] ?? 0);
        $now = new UTCDateTime((int) now()->getTimestampMs());

        $context->collection(self::COLLECTION)->updateOne(
            ['_id' => $this->headId($parentProfileId, $groupId)],
            [
                '$setOnInsert' => [
                    'tenant_id' => $tenantId,
                    'parent_type' => self::PARENT_TYPE,
                    'parent_id' => $parentProfileId,
                    'group_key' => $groupId,
                    'group_label' => $groupLabel,
                    'group_order' => $groupOrder,
                    'doc_type' => self::DOC_TYPE_HEAD,
                ],
                '$set' => [
                    'updated_at' => $now,
                ],
            ],
            [...$context->rawOptions(), 'upsert' => true],
        );

        $context->collection(self::COLLECTION)->deleteMany(
            [
                'tenant_id' => $tenantId,
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $parentProfileId,
                'group_key' => $groupId,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            $context->rawOptions(),
        );

        if ($memberIds === []) {
            return;
        }

        $rows = [];
        foreach (array_values($memberIds) as $position => $memberId) {
            $memberId = trim($memberId);
            if ($memberId === '') {
                continue;
            }

            $rows[] = [
                '_id' => $this->memberId($parentProfileId, $groupId, $memberId),
                'tenant_id' => $tenantId,
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $parentProfileId,
                'group_key' => $groupId,
                'group_order' => $groupOrder,
                'item_order' => $position,
                'doc_type' => self::DOC_TYPE_MEMBER,
                'nested_profile' => $this->nestedProfileDocument(
                    $memberId,
                    $profilesById[$memberId] ?? null,
                ),
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            $context->collection(self::COLLECTION)->insertMany($rows, $context->rawOptions());
        }
    }

    private function syncGroupMemberMetadataWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $groupId,
        string $groupLabel,
        int $groupOrder,
    ): void {
        $context->collection(self::COLLECTION)->updateMany(
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => (string) $profile->getKey(),
                'group_key' => $groupId,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                '$set' => [
                    'group_order' => $groupOrder,
                    'updated_at' => new UTCDateTime((int) now()->getTimestampMs()),
                ],
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
        $rows = iterator_to_array($collection->find(
            [
                'tenant_id' => $this->tenantId(),
                'parent_type' => self::PARENT_TYPE,
                'parent_id' => $parentProfileId,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                'projection' => ['group_key' => 1],
                ...$options,
            ],
        ));

        $counts = [];
        foreach ($rows as $row) {
            $document = $this->documentToArray($row) ?? [];
            $groupId = trim((string) ($document['group_key'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            $counts[$groupId] = ($counts[$groupId] ?? 0) + 1;
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
     * @param  array<int, string>  $memberIds
     * @param  array<string, AccountProfile>  $seedProfilesById
     * @return array<string, AccountProfile>
     */
    private function profilesByIdForIds(array $memberIds, array $seedProfilesById = []): array
    {
        $profilesById = [];
        foreach ($seedProfilesById as $profileId => $profile) {
            if ($profile instanceof AccountProfile) {
                $normalizedId = trim((string) $profileId);
                if ($normalizedId === '') {
                    $normalizedId = trim((string) $profile->getKey());
                }
                if ($normalizedId !== '') {
                    $profilesById[$normalizedId] = $profile;
                }
            }
        }

        $normalizedMemberIds = $this->normalizedStrings($memberIds);
        $missingIds = array_values(array_diff($normalizedMemberIds, array_keys($profilesById)));
        if ($missingIds === []) {
            return $profilesById;
        }

        foreach (
            AccountProfile::withTrashed()
                ->whereIn('_id', $missingIds)
                ->get([
                    '_id',
                    'display_name',
                    'name_search_key',
                    'profile_type',
                    'slug',
                    'avatar_url',
                    'cover_url',
                    'taxonomy_terms_flat',
                ]) as $profile
        ) {
            if (! $profile instanceof AccountProfile) {
                continue;
            }

            $profileId = trim((string) $profile->getKey());
            if ($profileId !== '') {
                $profilesById[$profileId] = $profile;
            }
        }

        return $profilesById;
    }

    /**
     * @return array<string, mixed>
     */
    private function nestedProfileDocument(string $memberProfileId, ?AccountProfile $profile): array
    {
        $memberProfileId = trim($memberProfileId);
        $profileType = trim((string) ($profile?->profile_type ?? ''));
        $label = trim((string) ($profile?->display_name ?? ''));
        $searchKey = trim((string) ($profile?->getAttribute('name_search_key') ?? ''));
        $slug = trim((string) ($profile?->slug ?? ''));

        return [
            'id' => $memberProfileId,
            'label' => $label === '' ? null : $label,
            'search_key' => $searchKey === '' ? null : $searchKey,
            'profile_type' => $profileType === '' ? null : $profileType,
            'category' => $profileType === '' ? null : $profileType,
            'taxonomy_terms_flat' => $this->normalizedStrings((array) ($profile?->getAttribute('taxonomy_terms_flat') ?? [])),
            'slug' => $slug === '' ? null : $slug,
            'avatar_url' => is_string($profile?->avatar_url ?? null) ? $profile->avatar_url : null,
            'cover_url' => is_string($profile?->cover_url ?? null) ? $profile->cover_url : null,
        ];
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
