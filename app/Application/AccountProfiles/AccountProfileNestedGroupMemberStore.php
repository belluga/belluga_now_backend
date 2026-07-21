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
    public const COLLECTION = 'account_profile_nested_group_members';

    private const DOC_TYPE_HEAD = 'group_head';

    private const DOC_TYPE_MEMBER = 'member_row';

    private const ADMIN_CURSOR_VERSION = 1;

    private const ADMIN_CURSOR_SCOPE = 'admin_nested_group_members';

    public function __construct(
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileNestedGroupService $nestedGroupService,
    ) {}

    public function materializeLegacyIfNeeded(AccountProfile $profile): void
    {
        $this->transactionRunner->run(
            fn (AccountProfileTransactionContext $context): null => $this->materializeLegacyIfNeededWithinContext(
                $context,
                $profile,
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function metadataGroups(AccountProfile $profile): array
    {
        $this->materializeLegacyIfNeeded($profile);

        return $this->metadataGroupsFromCollection((string) $profile->getKey());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function metadataGroupsWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
    ): array {
        $this->materializeLegacyIfNeededWithinContext($context, $profile);

        return $this->metadataGroupsFromCollection((string) $profile->getKey(), $context);
    }

    /**
     * @return array{aggregate_revision:int,data: array<int, array<string, mixed>>,next_cursor:?string}
     */
    public function adminMemberPage(
        AccountProfile $parentProfile,
        string $groupId,
        int $defaultPerPage,
        ?int $suppliedPerPage,
        ?string $cursor,
        AccountProfileCandidateDiscoveryService $candidateDiscoveryService,
    ): array {
        $this->materializeLegacyIfNeeded($parentProfile);

        $parentProfileId = (string) $parentProfile->getKey();
        $group = $this->findGroupHeadOrFail($parentProfileId, $groupId);
        $perPage = $defaultPerPage;
        $offset = 0;
        $aggregateRevision = max(0, (int) ($parentProfile->aggregate_revision ?? 0));

        if ($cursor !== null) {
            $payload = $this->decodeAdminCursor($cursor);
            if (($payload['scope'] ?? null) !== self::ADMIN_CURSOR_SCOPE
                || ($payload['parent_profile_id'] ?? null) !== $parentProfileId
                || ($payload['group_id'] ?? null) !== (string) ($group['group_id'] ?? '')) {
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
                'parent_profile_id' => $parentProfileId,
                'group_id' => (string) ($group['group_id'] ?? ''),
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                'sort' => ['raw_position' => 1, '_id' => 1],
                'skip' => $offset,
                'limit' => $perPage + 1,
            ],
        ));

        $memberIds = array_values(array_filter(array_map(function (array|object $row): string {
            $document = $this->documentToArray($row) ?? [];

            return trim((string) ($document['member_profile_id'] ?? ''));
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
                'group_id' => (string) ($group['group_id'] ?? ''),
                'per_page' => $perPage,
                'offset' => $offset + $perPage,
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'aggregate_revision' => $aggregateRevision,
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
        $this->materializeLegacyIfNeededWithinContext($context, $profile);

        $rows = iterator_to_array($context->collection(self::COLLECTION)->find(
            [
                'tenant_id' => $this->tenantId(),
                'parent_profile_id' => (string) $profile->getKey(),
                'group_id' => $groupId,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                'sort' => ['raw_position' => 1, '_id' => 1],
                ...$context->rawOptions(),
            ],
        ));

        return array_values(array_filter(array_map(function (array|object $row): string {
            $document = $this->documentToArray($row) ?? [];

            return trim((string) ($document['member_profile_id'] ?? ''));
        }, $rows)));
    }

    public function syncGroupsWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
    ): void {
        $groups = $this->nestedGroupService->formatForRead($profile->nested_profile_groups ?? []);
        $tenantId = $this->tenantId();
        $parentProfileId = (string) $profile->getKey();
        $now = new UTCDateTime((int) now()->getTimestampMs());
        $groupIds = [];

        foreach ($groups as $group) {
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            $groupIds[] = $groupId;
            $context->collection(self::COLLECTION)->updateOne(
                [
                    '_id' => $this->headId($parentProfileId, $groupId),
                ],
                [
                    '$set' => [
                        'tenant_id' => $tenantId,
                        'parent_profile_id' => $parentProfileId,
                        'group_id' => $groupId,
                        'group_label' => (string) ($group['label'] ?? ''),
                        'group_order' => (int) ($group['order'] ?? 0),
                        'doc_type' => self::DOC_TYPE_HEAD,
                        'updated_at' => $now,
                    ],
                ],
                [...$context->rawOptions(), 'upsert' => true],
            );
        }

        $filter = [
            'tenant_id' => $tenantId,
            'parent_profile_id' => $parentProfileId,
        ];

        if ($groupIds === []) {
            $context->collection(self::COLLECTION)->deleteMany($filter, $context->rawOptions());

            return;
        }

        $context->collection(self::COLLECTION)->deleteMany(
            [
                ...$filter,
                'group_id' => ['$nin' => $groupIds],
            ],
            $context->rawOptions(),
        );
    }

    /**
     * @param  array<int, string>  $memberIds
     */
    public function replaceGroupMembersWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
        string $groupId,
        array $memberIds,
    ): void {
        $tenantId = $this->tenantId();
        $parentProfileId = (string) $profile->getKey();
        $now = new UTCDateTime((int) now()->getTimestampMs());

        $context->collection(self::COLLECTION)->deleteMany(
            [
                'tenant_id' => $tenantId,
                'parent_profile_id' => $parentProfileId,
                'group_id' => $groupId,
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
                'parent_profile_id' => $parentProfileId,
                'group_id' => $groupId,
                'member_profile_id' => $memberId,
                'raw_position' => $position,
                'doc_type' => self::DOC_TYPE_MEMBER,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            $context->collection(self::COLLECTION)->insertMany($rows, $context->rawOptions());
        }
    }

    public function materializeLegacyIfNeededWithinContext(
        AccountProfileTransactionContext $context,
        AccountProfile $profile,
    ): null {
        $profileId = (string) $profile->getKey();
        if ($profileId === '') {
            return null;
        }

        $groups = $this->nestedGroupService->formatForRead($profile->nested_profile_groups ?? []);
        if ($groups === []) {
            return null;
        }

        $existing = $context->collection(self::COLLECTION)->countDocuments(
            [
                'tenant_id' => $this->tenantId(),
                'parent_profile_id' => $profileId,
            ],
            $context->rawOptions(),
        );
        if ($existing > 0) {
            return null;
        }

        $now = new UTCDateTime((int) now()->getTimestampMs());
        $rows = [];
        foreach ($groups as $group) {
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            $rows[] = [
                '_id' => $this->headId($profileId, $groupId),
                'tenant_id' => $this->tenantId(),
                'parent_profile_id' => $profileId,
                'group_id' => $groupId,
                'group_label' => (string) ($group['label'] ?? ''),
                'group_order' => (int) ($group['order'] ?? 0),
                'doc_type' => self::DOC_TYPE_HEAD,
                'updated_at' => $now,
            ];

            foreach (array_values($group['account_profile_ids'] ?? []) as $position => $memberId) {
                $memberId = trim((string) $memberId);
                if ($memberId === '') {
                    continue;
                }

                $rows[] = [
                    '_id' => $this->memberId($profileId, $groupId, $memberId),
                    'tenant_id' => $this->tenantId(),
                    'parent_profile_id' => $profileId,
                    'group_id' => $groupId,
                    'member_profile_id' => $memberId,
                    'raw_position' => $position,
                    'doc_type' => self::DOC_TYPE_MEMBER,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            $context->collection(self::COLLECTION)->insertMany($rows, $context->rawOptions());
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function findGroupHeadOrFail(string $parentProfileId, string $groupId): array
    {
        $row = $this->documentToArray($this->collection()->findOne([
            'tenant_id' => $this->tenantId(),
            'parent_profile_id' => $parentProfileId,
            'group_id' => $groupId,
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
    ): array
    {
        $collection = $context?->collection(self::COLLECTION) ?? $this->collection();
        $options = $context?->rawOptions() ?? [];
        $rows = iterator_to_array($collection->find(
            [
                'tenant_id' => $this->tenantId(),
                'parent_profile_id' => $parentProfileId,
                'doc_type' => self::DOC_TYPE_MEMBER,
            ],
            [
                'projection' => ['group_id' => 1],
                ...$options,
            ],
        ));

        $counts = [];
        foreach ($rows as $row) {
            $document = $this->documentToArray($row) ?? [];
            $groupId = trim((string) ($document['group_id'] ?? ''));
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
                'parent_profile_id' => $parentProfileId,
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
            $groupId = trim((string) ($document['group_id'] ?? ''));

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
        return 'group-head:'.$parentProfileId.':'.$groupId;
    }

    private function memberId(string $parentProfileId, string $groupId, string $memberProfileId): string
    {
        return 'group-member:'.$parentProfileId.':'.$groupId.':'.$memberProfileId;
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
