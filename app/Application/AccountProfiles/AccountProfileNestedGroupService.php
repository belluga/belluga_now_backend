<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\Taxonomies\TaxonomyTermSummaryResolverService;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use App\Support\Validation\InputConstraints;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class AccountProfileNestedGroupService
{
    public function __construct(
        private readonly AccountProfileMediaService $mediaService,
        private readonly TaxonomyTermSummaryResolverService $taxonomyTermSummaryResolver,
        private readonly AccountProfileTypeCapabilityCatalog $capabilityCatalog,
        private readonly AccountProfileTypeSetProvider $typeSetProvider,
        private readonly AccountProfileCandidateDiscoveryService $candidateDiscoveryService,
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
            ];
        }

        $this->assertMemberProfilesExist(array_keys($allMemberIds));

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
     * @return array<int, array<string, mixed>>
     */
    public function formatForPublicDetail(AccountProfile $parentProfile, string $baseUrl): array
    {
        if (! $this->parentProfileTypeAllowsNestedGroups($parentProfile)) {
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

        $queryableTypes = $this->queryableProfileTypes();
        if ($queryableTypes === []) {
            return [];
        }

        $publicCatalogTypes = $this->publicCatalogTypes();
        $profilesById = $this->publicProfilesById(array_keys($orderedMemberIds), $queryableTypes);
        $publicGroups = [];
        foreach ($groups as $group) {
            $profiles = [];
            foreach ($group['account_profile_ids'] as $memberId) {
                $profile = $profilesById[$memberId] ?? null;
                if (! $profile) {
                    continue;
                }
                $profiles[] = $this->formatLinkedProfile($profile, $baseUrl, $publicCatalogTypes);
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

    private function parentProfileTypeAllowsNestedGroups(AccountProfile $parentProfile): bool
    {
        $profileType = trim((string) ($parentProfile->profile_type ?? ''));
        if ($profileType === '') {
            return false;
        }

        $type = TenantProfileType::query()
            ->where('type', $profileType)
            ->first();
        $capabilities = $this->arrayFrom($type?->capabilities ?? []);

        return $this->capabilityCatalog->isExplicitlyEnabled(
            AccountProfileTypeCapabilityCatalog::HAS_NESTED_PROFILE_GROUPS,
            $capabilities,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
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
     */
    private function assertMemberProfilesExist(array $memberIds): void
    {
        if ($memberIds === []) {
            return;
        }

        $profiles = $this->candidateDiscoveryService->eligibleProfilesByIds(
            AccountProfileCandidateDiscoveryService::SCOPE_QUERYABLE,
            $memberIds,
        );
        $profilesById = [];
        foreach ($profiles as $profile) {
            $profilesById[(string) $profile->getKey()] = $profile;
        }

        $invalid = [];
        foreach ($memberIds as $memberId) {
            $profile = $profilesById[$memberId] ?? null;
            if (
                ! $profile
                || ! $profile->is_active
            ) {
                $invalid[] = $memberId;
            }
        }

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'nested_profile_groups' => ['Nested profile group includes unavailable or non-queryable profiles.'],
            ]);
        }
    }

    /**
     * @param  array<int, string>  $memberIds
     * @param  array<int, string>  $queryableTypes
     * @return array<string, AccountProfile>
     */
    private function publicProfilesById(array $memberIds, array $queryableTypes): array
    {
        if ($memberIds === [] || $queryableTypes === []) {
            return [];
        }

        $profiles = AccountProfile::query()
            ->whereIn('_id', $memberIds)
            ->where('is_active', true)
            ->where('visibility', 'public')
            ->whereIn('profile_type', $queryableTypes)
            ->get();
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
     * @return array<string, mixed>
     */
    private function formatLinkedProfile(AccountProfile $profile, string $baseUrl, array $publicCatalogTypes): array
    {
        $slug = trim((string) ($profile->slug ?? ''));
        $canOpenPublicDetail = $slug !== '' && in_array((string) $profile->profile_type, $publicCatalogTypes, true);

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

    /**
     * @return array<int, string>
     */
    private function queryableProfileTypes(): array
    {
        return $this->typeSetProvider->queryableTypes();
    }

    /**
     * @return array<int, string>
     */
    private function publicCatalogTypes(): array
    {
        return $this->typeSetProvider->publicCatalogTypes();
    }
}
