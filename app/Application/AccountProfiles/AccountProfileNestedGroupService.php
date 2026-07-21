<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\AccountProfile;
use App\Support\Validation\InputConstraints;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AccountProfileNestedGroupService
{
    private const ADMIN_CURSOR_VERSION = 1;

    private const ADMIN_CURSOR_SCOPE = 'admin_nested_group_members';

    public function __construct(
        private readonly AccountProfileMediaService $mediaService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalizeForWrite(mixed $rawGroups, ?string $parentProfileId = null): array
    {
        if (! is_array($rawGroups)) {
            return [];
        }

        if (count($rawGroups) > InputConstraints::ACCOUNT_PROFILE_NESTED_GROUPS_MAX) {
            throw ValidationException::withMessages([
                'nested_profile_groups' => ['Nested profile groups exceed the configured limit.'],
            ]);
        }

        $groups = [];
        $groupIds = [];
        $allMemberIds = [];

        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$index}" => ['Nested profile group must be an object.'],
                ]);
            }

            $label = trim((string) ($rawGroup['label'] ?? ''));
            if ($label === '') {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$index}.label" => ['Nested profile group label is required.'],
                ]);
            }

            $id = $this->normalizeGroupId($rawGroup['id'] ?? $rawGroup['key'] ?? null, $label, $index);
            if (isset($groupIds[$id])) {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$index}.id" => ['Nested profile group ids must be unique.'],
                ]);
            }
            $groupIds[$id] = true;

            $memberIds = $this->normalizeMemberIds(
                $rawGroup['account_profile_ids'] ?? $rawGroup['profile_ids'] ?? [],
                $index,
                $parentProfileId
            );
            foreach ($memberIds as $memberId) {
                $allMemberIds[$memberId] = true;
            }

            $groups[] = [
                '_source_index' => $index,
                'id' => $id,
                'label' => $label,
                'order' => isset($rawGroup['order']) ? (int) $rawGroup['order'] : $index,
                'account_profile_ids' => $memberIds,
                'member_count' => isset($rawGroup['member_count'])
                    ? max(0, (int) $rawGroup['member_count'])
                    : count($memberIds),
            ];
        }

        usort(
            $groups,
            static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                <=> [$right['order'], $right['_source_index']]
        );

        return array_values(array_map(
            static fn (array $group): array => [
                'id' => $group['id'],
                'label' => $group['label'],
                'order' => $group['order'],
                'account_profile_ids' => $group['account_profile_ids'],
                'member_count' => max(0, (int) ($group['member_count'] ?? count($group['account_profile_ids']))),
            ],
            $groups
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function normalizeMetadataForWrite(mixed $rawGroups): array
    {
        if (! is_array($rawGroups)) {
            return [];
        }

        if (count($rawGroups) > InputConstraints::ACCOUNT_PROFILE_NESTED_GROUPS_MAX) {
            throw ValidationException::withMessages([
                'nested_profile_groups' => ['Nested profile groups exceed the configured limit.'],
            ]);
        }

        $groups = [];
        $groupIds = [];

        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$index}" => ['Nested profile group must be an object.'],
                ]);
            }

            $label = trim((string) ($rawGroup['label'] ?? ''));
            if ($label === '') {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$index}.label" => ['Nested profile group label is required.'],
                ]);
            }

            $id = $this->normalizeGroupId($rawGroup['id'] ?? $rawGroup['key'] ?? null, $label, $index);
            if (isset($groupIds[$id])) {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$index}.id" => ['Nested profile group ids must be unique.'],
                ]);
            }
            $groupIds[$id] = true;

            $groups[] = [
                '_source_index' => $index,
                'id' => $id,
                'label' => $label,
                'order' => isset($rawGroup['order']) ? (int) $rawGroup['order'] : $index,
                'member_count' => max(0, (int) ($rawGroup['member_count'] ?? 0)),
            ];
        }

        usort(
            $groups,
            static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                <=> [$right['order'], $right['_source_index']]
        );

        return array_values(array_map(
            static fn (array $group): array => [
                'id' => $group['id'],
                'label' => $group['label'],
                'order' => $group['order'],
                'member_count' => $group['member_count'],
            ],
            $groups
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formatForRead(mixed $rawGroups): array
    {
        if (! is_array($rawGroups)) {
            return [];
        }

        $groups = [];
        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                continue;
            }

            $label = trim((string) ($rawGroup['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $id = $this->normalizeGroupId($rawGroup['id'] ?? $rawGroup['key'] ?? null, $label, $index);
            $memberIds = [];
            $rawMemberIds = $rawGroup['account_profile_ids'] ?? $rawGroup['profile_ids'] ?? [];
            if (is_array($rawMemberIds)) {
                foreach ($rawMemberIds as $rawMemberId) {
                    $memberId = trim((string) $rawMemberId);
                    if ($memberId !== '' && ! in_array($memberId, $memberIds, true)) {
                        $memberIds[] = $memberId;
                    }
                }
            }

            $groups[] = [
                '_source_index' => $index,
                'id' => $id,
                'label' => $label,
                'order' => isset($rawGroup['order']) ? (int) $rawGroup['order'] : $index,
                'account_profile_ids' => $memberIds,
            ];
        }

        usort(
            $groups,
            static fn (array $left, array $right): int => [$left['order'], $left['_source_index']]
                <=> [$right['order'], $right['_source_index']]
        );

        return array_values(array_map(
            static fn (array $group): array => [
                'id' => $group['id'],
                'label' => $group['label'],
                'order' => $group['order'],
                'account_profile_ids' => $group['account_profile_ids'],
                'member_count' => max(0, (int) ($group['member_count'] ?? count($group['account_profile_ids']))),
            ],
            $groups
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<string, array{id: string, display_name: ?string, is_queryable_candidate: bool, is_contact_capable_candidate: bool}>  $summariesByProfileId
     * @return array<int, array<string, mixed>>
     */
    public function withSelectedSummaries(array $groups, array $summariesByProfileId): array
    {
        return array_values(array_map(
            static fn (array $group): array => [
                ...$group,
                'account_profile_summaries' => array_values(array_map(
                    static fn (string $profileId): array => $summariesByProfileId[$profileId] ?? [
                        'id' => $profileId,
                        'display_name' => null,
                        'is_queryable_candidate' => false,
                        'is_contact_capable_candidate' => false,
                    ],
                    $group['account_profile_ids'],
                )),
            ],
            $groups,
        ));
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
        $groups = $this->formatForRead($parentProfile->nested_profile_groups ?? []);
        $group = $this->findGroupOrFail($groups, $groupId);

        $perPage = $defaultPerPage;
        $offset = 0;
        $aggregateRevision = max(0, (int) ($parentProfile->aggregate_revision ?? 0));
        $parentProfileId = (string) $parentProfile->getKey();

        if ($cursor !== null) {
            $payload = $this->decodeAdminCursor($cursor);
            if (($payload['scope'] ?? null) !== self::ADMIN_CURSOR_SCOPE
                || ($payload['parent_profile_id'] ?? null) !== $parentProfileId
                || ($payload['group_id'] ?? null) !== $group['id']) {
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
                throw ValidationException::withMessages([
                    'cursor' => ['Nested profile member cursor is stale for the current aggregate revision.'],
                ]);
            }

            $perPage = $cursorPerPage;
            $offset = max(0, (int) ($payload['offset'] ?? 0));
        }

        $memberIds = array_values($group['account_profile_ids'] ?? []);
        $pageIds = array_slice($memberIds, $offset, $perPage);
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
        if (($offset + $perPage) < count($memberIds)) {
            $nextCursor = Crypt::encryptString(json_encode([
                'version' => self::ADMIN_CURSOR_VERSION,
                'scope' => self::ADMIN_CURSOR_SCOPE,
                'parent_profile_id' => $parentProfileId,
                'group_id' => $group['id'],
                'aggregate_revision' => $aggregateRevision,
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
     * @return array<int, array<string, mixed>>
     */
    public function formatForPublicDetail(
        AccountProfile $parentProfile,
        string $baseUrl,
        AccountProfilePublicCatalogEligibilityPolicy $publicCatalogPolicy,
    ): array {
        if (! $publicCatalogPolicy->isPublicNestedParent($parentProfile)) {
            return [];
        }

        $groups = $this->formatForRead($parentProfile->nested_profile_groups ?? []);
        if ($groups === []) {
            return [];
        }

        $orderedMemberIds = [];
        foreach ($groups as $group) {
            foreach ($group['account_profile_ids'] as $memberId) {
                $orderedMemberIds[$memberId] = true;
            }
        }

        $profilesById = $this->publicProfilesById(
            array_keys($orderedMemberIds),
            $publicCatalogPolicy,
        );
        $publicGroups = [];
        foreach ($groups as $group) {
            $profiles = [];
            foreach ($group['account_profile_ids'] as $memberId) {
                $profile = $profilesById[$memberId] ?? null;
                if (! $profile) {
                    continue;
                }
                $profiles[] = $this->formatLinkedProfile($profile, $baseUrl, $publicCatalogPolicy);
            }

            if ($profiles === []) {
                continue;
            }

            $publicGroups[] = [
                'id' => $group['id'],
                'label' => $group['label'],
                'order' => $group['order'],
                'profiles' => $profiles,
            ];
        }

        return $publicGroups;
    }

    private function normalizeGroupId(mixed $rawId, string $label, int $index): string
    {
        $id = trim((string) ($rawId ?? ''));
        if ($id === '') {
            $id = Str::slug($label);
        }
        if ($id === '') {
            $id = 'group-'.$index;
        }
        $id = Str::lower($id);

        if (
            strlen($id) > InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_KEY_MAX
            || ! preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $id)
        ) {
            throw ValidationException::withMessages([
                'nested_profile_groups' => ['Nested profile group id is invalid.'],
            ]);
        }

        return $id;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeMemberIds(mixed $rawMemberIds, int $groupIndex, ?string $parentProfileId): array
    {
        if (! is_array($rawMemberIds)) {
            return [];
        }

        if (count($rawMemberIds) > InputConstraints::ACCOUNT_PROFILE_NESTED_GROUP_MEMBERS_MAX) {
            throw ValidationException::withMessages([
                "nested_profile_groups.{$groupIndex}.account_profile_ids" => ['Nested profile group members exceed the configured limit.'],
            ]);
        }

        $memberIds = [];
        $seen = [];
        foreach ($rawMemberIds as $memberIndex => $rawMemberId) {
            $memberId = trim((string) $rawMemberId);
            if ($memberId === '') {
                continue;
            }
            if (! preg_match('/^[a-f0-9]{24}$/i', $memberId)) {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$groupIndex}.account_profile_ids.{$memberIndex}" => ['Nested profile member id is invalid.'],
                ]);
            }
            if ($parentProfileId !== null && $memberId === $parentProfileId) {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$groupIndex}.account_profile_ids" => ['A profile cannot link itself as a nested profile.'],
                ]);
            }
            if (isset($seen[$memberId])) {
                throw ValidationException::withMessages([
                    "nested_profile_groups.{$groupIndex}.account_profile_ids" => ['Nested profile group members must be unique.'],
                ]);
            }
            $seen[$memberId] = true;
            $memberIds[] = $memberId;
        }

        return $memberIds;
    }

    /**
     * @param  array<int, string>  $memberIds
     * @return array<string, AccountProfile>
     */
    private function publicProfilesById(
        array $memberIds,
        AccountProfilePublicCatalogEligibilityPolicy $publicCatalogPolicy,
    ): array {
        if ($memberIds === []) {
            return [];
        }

        $profiles = $publicCatalogPolicy->applyCatalogConstraint(
            AccountProfile::query()->whereIn('_id', $memberIds),
        )->get();
        $byId = [];
        foreach ($profiles as $profile) {
            $byId[(string) $profile->getKey()] = $profile;
        }

        return $byId;
    }

    private function findProfileById(string $profileId): ?AccountProfile
    {
        $profile = AccountProfile::query()->find($profileId);
        if ($profile instanceof AccountProfile) {
            return $profile;
        }

        try {
            $profile = AccountProfile::query()
                ->where('_id', new ObjectId($profileId))
                ->first();
        } catch (\Throwable) {
            $profile = null;
        }

        return $profile instanceof AccountProfile ? $profile : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    public function findGroupOrFail(array $groups, string $groupId): array
    {
        $normalizedGroupId = Str::lower(trim($groupId));
        foreach ($groups as $group) {
            if (trim((string) ($group['id'] ?? '')) === $normalizedGroupId) {
                return $group;
            }
        }

        throw new NotFoundHttpException;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAdminCursor(string $cursor): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'cursor' => ['Nested profile member cursor is invalid.'],
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'cursor' => ['Nested profile member cursor is invalid.'],
            ]);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLinkedProfile(
        AccountProfile $profile,
        string $baseUrl,
        AccountProfilePublicCatalogEligibilityPolicy $publicCatalogPolicy,
    ): array {
        $slug = trim((string) ($profile->slug ?? ''));
        $canOpenPublicDetail = $publicCatalogPolicy->canOpenPublicDetail($profile);

        return [
            'id' => (string) $profile->_id,
            'account_id' => (string) $profile->account_id,
            'profile_type' => $profile->profile_type,
            'display_name' => $profile->display_name,
            'slug' => $slug !== '' ? $slug : null,
            'avatar_url' => $this->mediaService->normalizePublicUrl(
                $baseUrl,
                $profile,
                'avatar',
                is_string($profile->avatar_url) ? $profile->avatar_url : null
            ),
            'cover_url' => $this->mediaService->normalizePublicUrl(
                $baseUrl,
                $profile,
                'cover',
                is_string($profile->cover_url) ? $profile->cover_url : null
            ),
            'taxonomy_terms' => $this->taxonomyTermSummaryResolver->ensureSnapshots(
                is_array($profile->taxonomy_terms ?? null) ? $profile->taxonomy_terms : []
            ),
            'can_open_public_detail' => $canOpenPublicDetail,
            'public_detail_path' => $canOpenPublicDetail ? '/parceiro/'.$slug : null,
        ];
    }
}
