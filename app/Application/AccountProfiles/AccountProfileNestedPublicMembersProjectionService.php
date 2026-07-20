<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
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
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AccountProfileNestedPublicMembersProjectionService
{
    public const COLLECTION = 'account_profile_nested_public_member_projection';

    private const CURSOR_VERSION = 1;

    private const CURSOR_SCOPE = 'public_nested_members';

    private const DOC_TYPE_HEAD = 'group_head';

    private const DOC_TYPE_EDGE = 'member_edge';

    private const HEAD_RANK = 0;

    private const EDGE_RANK = 1;

    public function __construct(
        private readonly AccountProfileTransactionRunner $transactionRunner,
        private readonly AccountProfileNestedGroupService $nestedGroupService,
        private readonly AccountProfilePublicCatalogSnapshotReader $publicCatalogSnapshotReader,
    ) {}

    public function rebuildForProfile(AccountProfile $profile): void
    {
        $this->transactionRunner->run(function (AccountProfileTransactionContext $context) use ($profile): void {
            $this->rebuildParentProjection($context, $profile);
            $this->refreshMemberEdges($context, $profile);
        });
    }

    /** @param array<string, mixed> $event */
    public function refreshImpactedByAccountProfileOutboxEvent(
        AccountProfileTransactionContext $context,
        array $event,
    ): void {
        $profileId = trim((string) ($event['profile_id'] ?? ''));
        if ($profileId === '') {
            throw new RuntimeException('Nested public members projection requires a profile id.');
        }

        if ((string) ($event['operation'] ?? '') === 'tombstone') {
            $this->deleteByProfileId($context, $profileId);

            return;
        }

        $profile = $this->findProfileById($profileId);
        if (! $profile instanceof AccountProfile) {
            $this->deleteByProfileId($context, $profileId);

            return;
        }

        $this->rebuildParentProjection($context, $profile);
        $this->refreshMemberEdges($context, $profile);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next_cursor: ?string}
     */
    public function publicMemberPage(
        string $parentSlug,
        string $groupId,
        int $defaultPerPage,
        ?int $suppliedPerPage,
        ?string $cursor,
    ): array {
        $tenantId = trim((string) (Tenant::current()?->getKey() ?? ''));
        if ($tenantId === '') {
            throw new NotFoundHttpException;
        }

        $head = $this->findHeadRow($tenantId, $parentSlug, $groupId);
        if ($head === null) {
            throw new NotFoundHttpException;
        }

        $perPage = $defaultPerPage;
        $lastEmittedRawPosition = -1;
        $aggregateRevision = (int) ($head['parent_aggregate_revision'] ?? 0);

        if ($cursor !== null) {
            $payload = $this->decodeCursor($cursor);
            if (($payload['scope'] ?? null) !== self::CURSOR_SCOPE
                || ($payload['tenant_id'] ?? null) !== $tenantId
                || ($payload['parent_slug'] ?? null) !== $parentSlug
                || ($payload['group_id'] ?? null) !== $groupId) {
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

            if ((int) ($payload['aggregate_revision'] ?? -1) !== $aggregateRevision) {
                throw new ConcurrencyConflictException('Account Profile nested profile groups revision changed.');
            }

            $perPage = $cursorPerPage;
            $lastEmittedRawPosition = (int) ($payload['last_emitted_raw_position'] ?? -1);
        }

        $rows = iterator_to_array($this->collection()->find(
            [
                'tenant_id' => $tenantId,
                'parent_slug' => $parentSlug,
                'group_id' => $groupId,
                '$or' => [
                    ['doc_type_rank' => self::HEAD_RANK],
                    [
                        'doc_type_rank' => self::EDGE_RANK,
                        'raw_position' => ['$gt' => $lastEmittedRawPosition],
                    ],
                ],
            ],
            [
                'sort' => ['doc_type_rank' => 1, 'raw_position' => 1, '_id' => 1],
                'limit' => $perPage + 2,
            ],
        ));

        $memberRows = [];
        foreach ($rows as $row) {
            $normalized = $this->documentToArray($row);
            if (is_array($normalized) && (int) ($normalized['doc_type_rank'] ?? -1) === self::EDGE_RANK) {
                $memberRows[] = $normalized;
            }
        }

        $pageRows = array_slice($memberRows, 0, $perPage);
        $nextCursor = null;
        if (count($memberRows) > $perPage && $pageRows !== []) {
            $nextCursor = Crypt::encryptString(json_encode([
                'version' => self::CURSOR_VERSION,
                'scope' => self::CURSOR_SCOPE,
                'tenant_id' => $tenantId,
                'parent_slug' => $parentSlug,
                'group_id' => $groupId,
                'aggregate_revision' => $aggregateRevision,
                'per_page' => $perPage,
                'last_emitted_raw_position' => (int) ($pageRows[array_key_last($pageRows)]['raw_position'] ?? -1),
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'data' => array_values(array_map(
                static fn (array $row): array => [
                    'id' => (string) ($row['member_profile_id'] ?? ''),
                    'profile_type' => (string) ($row['profile_type'] ?? ''),
                    'display_name' => (string) ($row['display_name'] ?? ''),
                    'slug' => ($slug = trim((string) ($row['slug'] ?? ''))) === '' ? null : $slug,
                    'avatar_url' => is_string($row['avatar_url'] ?? null) ? $row['avatar_url'] : null,
                    'cover_url' => is_string($row['cover_url'] ?? null) ? $row['cover_url'] : null,
                    'taxonomy_terms' => is_array($row['taxonomy_terms'] ?? null) ? $row['taxonomy_terms'] : [],
                    'can_open_public_detail' => ($slug = trim((string) ($row['slug'] ?? ''))) !== '',
                    'public_detail_path' => ($slug = trim((string) ($row['slug'] ?? ''))) === '' ? null : '/parceiro/'.$slug,
                ],
                $pageRows,
            )),
            'next_cursor' => $nextCursor,
        ];
    }

    private function rebuildParentProjection(AccountProfileTransactionContext $context, AccountProfile $profile): void
    {
        $profileId = (string) $profile->getKey();
        $context->collection(self::COLLECTION)->deleteMany(
            ['parent_profile_id' => $profileId],
            $context->rawOptions(),
        );

        $policy = $this->publicCatalogSnapshotReader->catalogSnapshot()->policy();
        if (! $policy->isPublicNestedParent($profile)) {
            return;
        }

        $tenantId = trim((string) (Tenant::current()?->getKey() ?? ''));
        $parentSlug = trim((string) ($profile->slug ?? ''));
        if ($tenantId === '' || $parentSlug === '') {
            return;
        }

        $groups = $this->nestedGroupService->formatForRead($profile->nested_profile_groups ?? []);
        if ($groups === []) {
            return;
        }

        $aggregateRevision = (int) ($profile->aggregate_revision ?? 0);
        $memberIds = [];
        foreach ($groups as $group) {
            foreach ($group['account_profile_ids'] ?? [] as $memberId) {
                $memberId = trim((string) $memberId);
                if ($memberId !== '') {
                    $memberIds[$memberId] = $memberId;
                }
            }
        }

        $eligibleMembers = $memberIds === []
            ? collect()
            : $policy->applyCatalogConstraint(AccountProfile::query()->whereIn('_id', array_values($memberIds)))
                ->get()
                ->keyBy(static fn (AccountProfile $member): string => (string) $member->getKey());

        $now = new UTCDateTime((int) now()->getTimestampMs());
        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                '_id' => 'head:'.$profileId.':'.$group['id'],
                'tenant_id' => $tenantId,
                'parent_profile_id' => $profileId,
                'parent_slug' => $parentSlug,
                'parent_profile_type' => (string) ($profile->profile_type ?? ''),
                'group_id' => $group['id'],
                'group_label' => $group['label'],
                'group_order' => (int) ($group['order'] ?? 0),
                'parent_aggregate_revision' => $aggregateRevision,
                'doc_type' => self::DOC_TYPE_HEAD,
                'doc_type_rank' => self::HEAD_RANK,
                'updated_at' => $now,
            ];

            foreach ($group['account_profile_ids'] ?? [] as $rawPosition => $memberId) {
                $member = $eligibleMembers->get((string) $memberId);
                if (! $member instanceof AccountProfile) {
                    continue;
                }

                $rows[] = [
                    '_id' => 'edge:'.$profileId.':'.$group['id'].':'.(string) $member->getKey(),
                    'tenant_id' => $tenantId,
                    'parent_profile_id' => $profileId,
                    'parent_slug' => $parentSlug,
                    'parent_profile_type' => (string) ($profile->profile_type ?? ''),
                    'group_id' => $group['id'],
                    'member_profile_id' => (string) $member->getKey(),
                    'member_profile_type' => (string) ($member->profile_type ?? ''),
                    'raw_position' => (int) $rawPosition,
                    'parent_aggregate_revision' => $aggregateRevision,
                    'doc_type' => self::DOC_TYPE_EDGE,
                    'doc_type_rank' => self::EDGE_RANK,
                    'profile_type' => (string) ($member->profile_type ?? ''),
                    'display_name' => (string) ($member->display_name ?? ''),
                    'slug' => trim((string) ($member->slug ?? '')) ?: null,
                    'avatar_url' => $member->avatar_url,
                    'cover_url' => $member->cover_url,
                    'taxonomy_terms' => is_array($member->taxonomy_terms ?? null) ? $member->taxonomy_terms : [],
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            $context->collection(self::COLLECTION)->insertMany($rows, $context->rawOptions());
        }
    }

    private function refreshMemberEdges(AccountProfileTransactionContext $context, AccountProfile $profile): void
    {
        $tenantId = trim((string) (Tenant::current()?->getKey() ?? ''));
        $profileId = (string) $profile->getKey();
        if ($tenantId === '') {
            return;
        }

        $policy = $this->publicCatalogSnapshotReader->catalogSnapshot()->policy();
        if (! $policy->isPubliclyExposed($profile)) {
            $context->collection(self::COLLECTION)->deleteMany(
                [
                    'tenant_id' => $tenantId,
                    'member_profile_id' => $profileId,
                    'doc_type' => self::DOC_TYPE_EDGE,
                ],
                $context->rawOptions(),
            );

            return;
        }

        $context->collection(self::COLLECTION)->updateMany(
            [
                'tenant_id' => $tenantId,
                'member_profile_id' => $profileId,
                'doc_type' => self::DOC_TYPE_EDGE,
            ],
            [
                '$set' => [
                    'member_profile_type' => (string) ($profile->profile_type ?? ''),
                    'profile_type' => (string) ($profile->profile_type ?? ''),
                    'display_name' => (string) ($profile->display_name ?? ''),
                    'slug' => trim((string) ($profile->slug ?? '')) ?: null,
                    'avatar_url' => $profile->avatar_url,
                    'cover_url' => $profile->cover_url,
                    'taxonomy_terms' => is_array($profile->taxonomy_terms ?? null) ? $profile->taxonomy_terms : [],
                    'updated_at' => new UTCDateTime((int) now()->getTimestampMs()),
                ],
            ],
            $context->rawOptions(),
        );
    }

    private function deleteByProfileId(AccountProfileTransactionContext $context, string $profileId): void
    {
        $context->collection(self::COLLECTION)->deleteMany(
            [
                '$or' => [
                    ['parent_profile_id' => $profileId],
                    ['member_profile_id' => $profileId],
                ],
            ],
            $context->rawOptions(),
        );
    }

    /** @return array<string, mixed>|null */
    private function findHeadRow(string $tenantId, string $parentSlug, string $groupId): ?array
    {
        return $this->documentToArray($this->collection()->findOne([
            'tenant_id' => $tenantId,
            'parent_slug' => $parentSlug,
            'group_id' => $groupId,
            'doc_type' => self::DOC_TYPE_HEAD,
        ]));
    }

    private function findProfileById(string $profileId): ?AccountProfile
    {
        $profile = AccountProfile::query()->find($profileId);
        if ($profile instanceof AccountProfile) {
            return $profile;
        }

        try {
            return AccountProfile::query()->where('_id', new ObjectId($profileId))->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function collection(): \MongoDB\Collection
    {
        $connection = DB::connection('tenant');
        if (! $connection instanceof Connection) {
            throw new RuntimeException('A MongoDB tenant connection is required for nested public members projection.');
        }

        return $connection->getDatabase()->selectCollection(self::COLLECTION);
    }

    /** @return array<string, mixed> */
    private function decodeCursor(string $cursor): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'cursor' => ['Nested profile member cursor is invalid.'],
            ]);
        }

        if (! is_array($payload) || (int) ($payload['version'] ?? 0) !== self::CURSOR_VERSION) {
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

    /** @return array<string, mixed>|null */
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
