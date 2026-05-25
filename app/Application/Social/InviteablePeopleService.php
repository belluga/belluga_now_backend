<?php

declare(strict_types=1);

namespace App\Application\Social;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\Invites\Models\Tenants\InviteablePeopleProjection;

class InviteablePeopleService
{
    public const int DEFAULT_PAGE_SIZE = 50;

    public const int MAX_PAGE_SIZE = 100;

    private const string REGISTRY_KEY = 'account_profile';

    private const string TARGET_TYPE = 'account_profile';

    private const int MAX_INVITEABLE_SOURCE_ROWS = 500;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function inviteableItemsFor(AccountUser $viewer, int $limit = self::DEFAULT_PAGE_SIZE): array
    {
        $viewerId = $this->userId($viewer);
        if ($viewerId === '') {
            return [];
        }

        $query = InviteablePeopleProjection::query()
            ->where('owner_user_id', $viewerId)
            ->where('is_inviteable', true)
            ->orderBy('sort_name')
            ->orderBy('receiver_account_profile_id');

        $query->limit(max(1, min(self::MAX_PAGE_SIZE, $limit)));

        return $query
            ->get()
            ->map(fn (InviteablePeopleProjection $projection): array => $this->projectionPayload($projection))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sourceInviteableItemsFor(AccountUser $viewer, ?int $sourceRowLimit = null): array
    {
        $viewerId = $this->userId($viewer);
        if ($viewerId === '') {
            return [];
        }
        $sourceLimit = $this->sourceRowLimit($sourceRowLimit);

        $contactDirectoryQuery = ContactHashDirectory::query()
            ->where('importing_user_id', $viewerId)
            ->whereNotNull('matched_user_id')
            ->where('matched_user_id', '!=', '')
            ->orderBy('_id');
        if ($sourceLimit !== null) {
            $contactDirectoryQuery->limit($sourceLimit);
        }
        $contactDirectories = $contactDirectoryQuery->get();

        $outboundFavoriteQuery = FavoriteEdge::query()
            ->where('owner_user_id', $viewerId)
            ->where('registry_key', self::REGISTRY_KEY)
            ->where('target_type', self::TARGET_TYPE)
            ->orderBy('favorited_at', 'desc')
            ->orderBy('_id');
        if ($sourceLimit !== null) {
            $outboundFavoriteQuery->limit($sourceLimit);
        }
        $outboundFavorites = $outboundFavoriteQuery->get();

        $matchedUserIds = $contactDirectories
            ->map(fn (ContactHashDirectory $directory): string => trim((string) ($directory->matched_user_id ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $outboundProfileIds = $outboundFavorites
            ->map(fn (FavoriteEdge $favorite): string => trim((string) ($favorite->target_id ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $personalProfilesByUserId = $this->personalProfilesByUserId([
            $viewerId,
            ...$matchedUserIds,
        ]);
        $viewerProfile = $personalProfilesByUserId[$viewerId] ?? null;

        $inboundFavorites = collect();
        if ($viewerProfile instanceof AccountProfile) {
            $inboundFavoriteQuery = FavoriteEdge::query()
                ->where('registry_key', self::REGISTRY_KEY)
                ->where('target_type', self::TARGET_TYPE)
                ->where('target_id', (string) $viewerProfile->_id)
                ->orderBy('favorited_at', 'desc')
                ->orderBy('_id');
            if ($sourceLimit !== null) {
                $inboundFavoriteQuery->limit($sourceLimit);
            }
            $inboundFavorites = $inboundFavoriteQuery->get();
        }

        $inboundOwnerUserIds = $inboundFavorites
            ->map(fn (FavoriteEdge $favorite): string => trim((string) ($favorite->owner_user_id ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $personalProfilesByUserId += $this->personalProfilesByUserId($inboundOwnerUserIds);

        $outboundProfilesById = $this->profilesById($outboundProfileIds);
        $profileOwnerIds = collect([
            ...array_values($personalProfilesByUserId),
            ...array_values($outboundProfilesById),
        ])
            ->map(fn (AccountProfile $profile): string => trim((string) ($profile->created_by ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $usersById = $this->usersById([
            ...$matchedUserIds,
            ...$inboundOwnerUserIds,
            ...$profileOwnerIds,
        ]);
        $capabilitiesByType = $this->capabilitiesByProfileType([
            ...array_values($personalProfilesByUserId),
            ...array_values($outboundProfilesById),
        ]);
        $items = [];

        foreach ($contactDirectories as $directory) {
            $matchedUserId = trim((string) ($directory->matched_user_id ?? ''));
            if ($matchedUserId === '') {
                continue;
            }

            $targetUser = $usersById[$matchedUserId] ?? null;
            if (! $targetUser instanceof AccountUser || ! $targetUser->isActive()) {
                continue;
            }

            $targetProfile = $personalProfilesByUserId[$matchedUserId] ?? null;
            if (! $targetProfile instanceof AccountProfile) {
                continue;
            }

            $this->mergeItem(
                items: $items,
                viewer: $viewer,
                viewerProfile: $viewerProfile,
                targetUser: $targetUser,
                targetProfile: $targetProfile,
                reasons: ['contact_match'],
                capabilitiesByType: $capabilitiesByType,
                contactHash: $this->nullableString($directory->contact_hash),
                contactType: $this->nullableString($directory->type),
                requireContactDiscoverability: true,
            );
        }

        foreach ($outboundFavorites as $favorite) {
            $targetProfile = $outboundProfilesById[(string) $favorite->target_id] ?? null;
            if (! $targetProfile instanceof AccountProfile) {
                continue;
            }

            $targetUser = null;
            $ownerId = $this->nullableString($targetProfile->created_by);
            if ($ownerId !== null && (string) ($targetProfile->created_by_type ?? '') === 'tenant') {
                $targetUser = $usersById[$ownerId] ?? null;
            }

            $this->mergeItem(
                items: $items,
                viewer: $viewer,
                viewerProfile: $viewerProfile,
                targetUser: $targetUser,
                targetProfile: $targetProfile,
                reasons: ['favorite_by_you'],
                capabilitiesByType: $capabilitiesByType,
                requireContactDiscoverability: false,
            );
        }

        foreach ($inboundFavorites as $favorite) {
            $ownerUserId = trim((string) ($favorite->owner_user_id ?? ''));
            $targetUser = $usersById[$ownerUserId] ?? null;
            if (! $targetUser instanceof AccountUser || ! $targetUser->isActive()) {
                continue;
            }

            $targetProfile = $personalProfilesByUserId[$ownerUserId] ?? null;
            if (! $targetProfile instanceof AccountProfile) {
                continue;
            }

            $this->mergeItem(
                items: $items,
                viewer: $viewer,
                viewerProfile: $viewerProfile,
                targetUser: $targetUser,
                targetProfile: $targetProfile,
                reasons: ['favorited_you'],
                capabilitiesByType: $capabilitiesByType,
                requireContactDiscoverability: false,
            );
        }

        foreach ($items as &$item) {
            $reasons = $item['inviteable_reasons'];
            if (
                in_array('favorite_by_you', $reasons, true)
                && in_array('favorited_you', $reasons, true)
                && ! in_array('friend', $reasons, true)
            ) {
                $item['inviteable_reasons'][] = 'friend';
                $item['source_tags'][] = 'friend';
            }

            $item['profile_exposure_level'] = $this->exposureLevel(
                viewerProfile: $viewerProfile,
                targetProfile: $item['_target_profile'],
                reasons: $item['inviteable_reasons'],
            );
            unset($item['_target_profile']);
        }
        unset($item);

        usort($items, static function (array $left, array $right): int {
            return strcmp((string) $left['display_name'], (string) $right['display_name']);
        });

        return array_values($items);
    }

    /**
     * Rebuild one owner/profile projection candidate from source relations.
     *
     * @return array<string, mixed>|null
     */
    public function sourceInviteableItemForProfile(AccountUser $viewer, AccountProfile|string $targetProfile): ?array
    {
        $viewerId = $this->userId($viewer);
        if ($viewerId === '') {
            return null;
        }

        if (! $targetProfile instanceof AccountProfile) {
            $profileId = trim($targetProfile);
            if ($profileId === '') {
                return null;
            }

            /** @var AccountProfile|null $targetProfile */
            $targetProfile = AccountProfile::query()
                ->where('_id', $profileId)
                ->where('deleted_at', null)
                ->first();
        }

        if (! $targetProfile instanceof AccountProfile) {
            return null;
        }

        $viewerProfile = $this->personalProfileForUserId($viewerId);
        $targetUser = $this->profileOwner($targetProfile);
        if ($this->isSelfTarget($viewer, $viewerProfile, $targetUser, $targetProfile)) {
            return null;
        }

        $capabilitiesByType = $this->capabilitiesByProfileType(array_values(array_filter(
            [$viewerProfile, $targetProfile],
            static fn (mixed $profile): bool => $profile instanceof AccountProfile,
        )));
        if (! $this->profileIsInviteable($targetProfile, $capabilitiesByType)) {
            return null;
        }

        $targetUserId = $targetUser instanceof AccountUser ? $this->userId($targetUser) : '';
        $targetUserIsActive = $targetUser instanceof AccountUser && $targetUser->isActive();
        $reasons = [];
        $contactHash = null;
        $contactType = null;

        if ($targetUserIsActive && $targetUserId !== '') {
            /** @var ContactHashDirectory|null $contactMatch */
            $contactMatch = ContactHashDirectory::query()
                ->where('importing_user_id', $viewerId)
                ->where('matched_user_id', $targetUserId)
                ->whereNotNull('matched_user_id')
                ->orderBy('last_seen_at', 'desc')
                ->orderBy('_id', 'desc')
                ->first();

            if ($contactMatch instanceof ContactHashDirectory && $this->profileIsDiscoverableByContacts($targetProfile)) {
                $reasons[] = 'contact_match';
                $contactHash = $this->nullableString($contactMatch->contact_hash);
                $contactType = $this->nullableString($contactMatch->type);
            }
        }

        if (FavoriteEdge::query()
            ->where('owner_user_id', $viewerId)
            ->where('registry_key', self::REGISTRY_KEY)
            ->where('target_type', self::TARGET_TYPE)
            ->where('target_id', (string) $targetProfile->_id)
            ->exists()) {
            $reasons[] = 'favorite_by_you';
        }

        if ($targetUserIsActive && $targetUserId !== '' && $viewerProfile instanceof AccountProfile && FavoriteEdge::query()
            ->where('owner_user_id', $targetUserId)
            ->where('registry_key', self::REGISTRY_KEY)
            ->where('target_type', self::TARGET_TYPE)
            ->where('target_id', (string) $viewerProfile->_id)
            ->exists()) {
            $reasons[] = 'favorited_you';
        }

        $reasons = array_values(array_unique($reasons));
        if (
            in_array('favorite_by_you', $reasons, true)
            && in_array('favorited_you', $reasons, true)
            && ! in_array('friend', $reasons, true)
        ) {
            $reasons[] = 'friend';
        }

        if ($reasons === []) {
            return null;
        }

        $payload = $this->itemPayload(
            viewerProfile: $viewerProfile,
            targetUser: $targetUserIsActive ? $targetUser : null,
            targetProfile: $targetProfile,
            reasons: $reasons,
            contactHash: $contactHash,
            contactType: $contactType,
        );
        $payload['profile_exposure_level'] = $this->exposureLevel(
            viewerProfile: $viewerProfile,
            targetProfile: $targetProfile,
            reasons: $reasons,
        );
        unset($payload['_target_profile']);

        return $payload;
    }

    /**
     * @return array{items:array<int, array<string, mixed>>,has_more:bool}
     */
    public function inviteablePageFor(AccountUser $viewer, int $page, int $pageSize): array
    {
        $offset = max(0, ($page - 1) * $pageSize);
        $viewerId = $this->userId($viewer);
        if ($viewerId === '') {
            return [
                'items' => [],
                'has_more' => false,
            ];
        }

        $rows = InviteablePeopleProjection::query()
            ->where('owner_user_id', $viewerId)
            ->where('is_inviteable', true)
            ->orderBy('sort_name')
            ->orderBy('receiver_account_profile_id')
            ->skip($offset)
            ->limit($pageSize + 1)
            ->get()
            ->map(fn (InviteablePeopleProjection $projection): array => $this->projectionPayload($projection))
            ->values()
            ->all();

        return [
            'items' => array_slice($rows, 0, $pageSize),
            'has_more' => count($rows) > $pageSize,
        ];
    }

    private function sourceRowLimit(?int $requested): ?int
    {
        if ($requested === null) {
            return null;
        }

        return max(1, min(self::MAX_INVITEABLE_SOURCE_ROWS, $requested));
    }

    /**
     * @return array<string, mixed>
     */
    private function projectionPayload(InviteablePeopleProjection $projection): array
    {
        return [
            'user_id' => $this->nullableString($projection->receiver_user_id),
            'receiver_account_profile_id' => (string) ($projection->receiver_account_profile_id ?? ''),
            'display_name' => (string) ($projection->display_name ?? ''),
            'avatar_url' => $this->nullableString($projection->avatar_url),
            'cover_url' => $this->nullableString($projection->cover_url),
            'profile_type' => (string) ($projection->profile_type ?? ''),
            'profile_exposure_level' => (string) ($projection->profile_exposure_level ?? 'aggregate_only'),
            'inviteable_reasons' => is_array($projection->inviteable_reasons) ? array_values(array_map('strval', $projection->inviteable_reasons)) : [],
            'source_tags' => is_array($projection->source_tags) ? array_values(array_map('strval', $projection->source_tags)) : [],
            'is_inviteable' => (bool) ($projection->is_inviteable ?? true),
            'contact_hash' => $this->nullableString($projection->contact_hash),
            'contact_type' => $this->nullableString($projection->contact_type),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function contactMatchPayloadFor(
        AccountUser $viewer,
        AccountUser $targetUser,
        string $type,
        string $hash,
    ): ?array {
        return $this->contactMatchPayloadsFor($viewer, [[
            'user' => $targetUser,
            'type' => $type,
            'hash' => $hash,
        ]])[$hash] ?? null;
    }

    /**
     * @param  array<int, array{user:AccountUser,type:string,hash:string}>  $matches
     * @return array<string, array<string, mixed>>
     */
    public function contactMatchPayloadsFor(AccountUser $viewer, array $matches): array
    {
        $viewerId = $this->userId($viewer);
        if ($viewerId === '' || $matches === []) {
            return [];
        }

        $targetUsers = collect($matches)
            ->map(fn (array $match): AccountUser => $match['user'])
            ->values();
        $targetUserIds = $targetUsers
            ->map(fn (AccountUser $user): string => $this->userId($user))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $personalProfilesByUserId = $this->personalProfilesByUserId([
            $viewerId,
            ...$targetUserIds,
        ]);
        $viewerProfile = $personalProfilesByUserId[$viewerId] ?? null;
        $capabilitiesByType = $this->capabilitiesByProfileType(array_values($personalProfilesByUserId));
        $payloads = [];

        foreach ($matches as $match) {
            $targetUser = $match['user'];
            $type = trim((string) $match['type']);
            $hash = trim((string) $match['hash']);
            $targetUserId = $this->userId($targetUser);
            if ($type === '' || $hash === '' || $targetUserId === '') {
                continue;
            }

            $targetProfile = $personalProfilesByUserId[$targetUserId] ?? null;
            $payload = $this->contactMatchPayloadFromMaps(
                viewer: $viewer,
                viewerProfile: $viewerProfile,
                targetUser: $targetUser,
                targetProfile: $targetProfile,
                capabilitiesByType: $capabilitiesByType,
                type: $type,
                hash: $hash,
            );
            if ($payload !== null) {
                $payloads[$hash] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * @param  array<string, array<string, mixed>>  $capabilitiesByType
     * @return array<string, mixed>|null
     */
    private function contactMatchPayloadFromMaps(
        AccountUser $viewer,
        ?AccountProfile $viewerProfile,
        AccountUser $targetUser,
        ?AccountProfile $targetProfile,
        array $capabilitiesByType,
        string $type,
        string $hash,
    ): ?array {
        if (! $targetProfile instanceof AccountProfile) {
            return null;
        }

        if ($this->isSelfTarget($viewer, $viewerProfile, $targetUser, $targetProfile)) {
            return null;
        }

        if (
            ! $this->profileIsInviteable($targetProfile, $capabilitiesByType)
            || ! $this->profileIsDiscoverableByContacts($targetProfile)
        ) {
            return null;
        }

        $payload = $this->itemPayload(
            viewerProfile: $viewerProfile,
            targetUser: $targetUser,
            targetProfile: $targetProfile,
            reasons: ['contact_match'],
            contactHash: $hash,
            contactType: $type,
        );
        unset($payload['_target_profile']);

        return $payload;
    }

    /**
     * @return array{user_id:string,receiver_account_profile_id:string,display_name:?string,avatar_url:?string}|null
     */
    public function recipientForAccountProfileId(string $accountProfileId): ?array
    {
        $profile = AccountProfile::query()->find($accountProfileId);
        if (! $profile instanceof AccountProfile || ! $this->profileIsInviteable($profile)) {
            return null;
        }

        $user = $this->profileOwner($profile);
        if (! $user instanceof AccountUser || ! $user->isActive()) {
            return null;
        }

        return [
            'user_id' => (string) $user->_id,
            'receiver_account_profile_id' => (string) $profile->_id,
            'display_name' => $this->displayName($profile, $user),
            'avatar_url' => $this->nullableString($profile->avatar_url),
        ];
    }

    /**
     * @return array{user_id:string,receiver_account_profile_id:string,display_name:?string,avatar_url:?string}|null
     */
    public function recipientForUserId(string $userId): ?array
    {
        $recipient = $this->recipientIdentityForUserId($userId);
        if ($recipient === null) {
            return null;
        }

        $profile = AccountProfile::query()->find($recipient['receiver_account_profile_id']);
        if (! $profile instanceof AccountProfile || ! $this->profileIsInviteable($profile)) {
            return null;
        }

        return $recipient;
    }

    /**
     * @return array{user_id:string,receiver_account_profile_id:string,display_name:?string,avatar_url:?string}|null
     */
    public function recipientIdentityForUserId(string $userId): ?array
    {
        $user = AccountUser::query()->find($userId);
        if (! $user instanceof AccountUser || ! $user->isActive()) {
            return null;
        }

        $profile = $this->personalProfileForUserId($userId);
        if (! $profile instanceof AccountProfile) {
            return null;
        }

        return [
            'user_id' => (string) $user->_id,
            'receiver_account_profile_id' => (string) $profile->_id,
            'display_name' => $this->displayName($profile, $user),
            'avatar_url' => $this->nullableString($profile->avatar_url),
        ];
    }

    /**
     * @return array<string, true>
     */
    public function inviteableProfileIdSetFor(AccountUser $viewer): array
    {
        $viewerId = $this->userId($viewer);
        if ($viewerId === '') {
            return [];
        }

        return InviteablePeopleProjection::query()
            ->where('owner_user_id', $viewerId)
            ->where('is_inviteable', true)
            ->get(['receiver_account_profile_id'])
            ->pluck('receiver_account_profile_id')
            ->reduce(static function (array $set, mixed $value): array {
                $profileId = trim((string) $value);
                if ($profileId !== '') {
                    $set[$profileId] = true;
                }

                return $set;
            }, []);
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<int, string>  $reasons
     */
    private function mergeItem(
        array &$items,
        AccountUser $viewer,
        ?AccountProfile $viewerProfile,
        ?AccountUser $targetUser,
        AccountProfile $targetProfile,
        array $reasons,
        array $capabilitiesByType = [],
        ?string $contactHash = null,
        ?string $contactType = null,
        bool $requireContactDiscoverability = false,
    ): void {
        if ($this->isSelfTarget($viewer, $viewerProfile, $targetUser, $targetProfile)) {
            return;
        }

        if (! $this->profileIsInviteable($targetProfile, $capabilitiesByType)) {
            return;
        }

        if ($requireContactDiscoverability && ! $this->profileIsDiscoverableByContacts($targetProfile)) {
            return;
        }

        $profileId = (string) $targetProfile->_id;
        $existing = $items[$profileId] ?? null;
        if (! is_array($existing)) {
            $existing = $this->itemPayload(
                viewerProfile: $viewerProfile,
                targetUser: $targetUser,
                targetProfile: $targetProfile,
                reasons: [],
                contactHash: $contactHash,
                contactType: $contactType,
            );
        }

        $mergedReasons = array_values(array_unique([
            ...($existing['inviteable_reasons'] ?? []),
            ...$reasons,
        ]));

        $existing['inviteable_reasons'] = $mergedReasons;
        $existing['source_tags'] = $mergedReasons;
        $existing['_target_profile'] = $targetProfile;
        if ($contactHash !== null && $contactHash !== '') {
            $existing['contact_hash'] = $contactHash;
        }
        if ($contactType !== null && $contactType !== '') {
            $existing['contact_type'] = $contactType;
        }

        $items[$profileId] = $existing;
    }

    /**
     * @param  array<int, string>  $reasons
     * @return array<string, mixed>
     */
    private function itemPayload(
        ?AccountProfile $viewerProfile,
        ?AccountUser $targetUser,
        AccountProfile $targetProfile,
        array $reasons,
        ?string $contactHash = null,
        ?string $contactType = null,
    ): array {
        $reasons = array_values(array_unique($reasons));

        return [
            'user_id' => $targetUser instanceof AccountUser ? (string) $targetUser->_id : null,
            'receiver_account_profile_id' => (string) $targetProfile->_id,
            'display_name' => $this->displayName($targetProfile, $targetUser),
            'avatar_url' => $this->nullableString($targetProfile->avatar_url),
            'cover_url' => $this->nullableString($targetProfile->cover_url),
            'profile_type' => (string) ($targetProfile->profile_type ?? ''),
            'profile_exposure_level' => $this->exposureLevel($viewerProfile, $targetProfile, $reasons),
            'inviteable_reasons' => $reasons,
            'source_tags' => $reasons,
            'is_inviteable' => true,
            'contact_hash' => $contactHash,
            'contact_type' => $contactType,
            '_target_profile' => $targetProfile,
        ];
    }

    /**
     * @param  array<int, string>  $reasons
     */
    private function exposureLevel(?AccountProfile $viewerProfile, AccountProfile $targetProfile, array $reasons): string
    {
        $visibility = $this->nullableString($targetProfile->privacy_mode)
            ?? $this->nullableString($targetProfile->visibility)
            ?? 'public';

        if ($visibility === 'public') {
            return 'full_profile';
        }

        if ($viewerProfile instanceof AccountProfile && in_array('favorited_you', $reasons, true)) {
            return 'full_profile';
        }

        if (array_intersect($reasons, ['contact_match', 'favorite_by_you', 'friend']) !== []) {
            return 'capped_profile';
        }

        return 'aggregate_only';
    }

    private function profileIsInviteable(AccountProfile $profile, array $capabilitiesByType = []): bool
    {
        if ((bool) ($profile->is_active ?? true) === false) {
            return false;
        }

        $profileType = (string) ($profile->profile_type ?? '');
        if (array_key_exists($profileType, $capabilitiesByType)) {
            return (bool) ($capabilitiesByType[$profileType]['is_inviteable'] ?? false);
        }

        /** @var TenantProfileType|null $type */
        $type = TenantProfileType::query()
            ->where('type', $profileType)
            ->first();

        $capabilities = is_array($type?->capabilities ?? null) ? $type->capabilities : [];

        return (bool) ($capabilities['is_inviteable'] ?? false);
    }

    private function profileIsDiscoverableByContacts(AccountProfile $profile): bool
    {
        return (bool) ($profile->discoverable_by_contacts ?? true);
    }

    private function personalProfileForUserId(string $userId): ?AccountProfile
    {
        if ($userId === '') {
            return null;
        }

        /** @var AccountProfile|null $profile */
        $profile = AccountProfile::query()
            ->where('created_by', $userId)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->where('deleted_at', null)
            ->orderBy('_id')
            ->first();

        return $profile;
    }

    /**
     * @param  array<int, string>  $userIds
     * @return array<string, AccountProfile>
     */
    private function personalProfilesByUserId(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $userIds),
            static fn (string $id): bool => $id !== ''
        )));
        if ($ids === []) {
            return [];
        }

        return AccountProfile::query()
            ->whereIn('created_by', $ids)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->where('deleted_at', null)
            ->orderBy('_id')
            ->get()
            ->keyBy(fn (AccountProfile $profile): string => (string) $profile->created_by)
            ->all();
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, AccountProfile>
     */
    private function profilesById(array $profileIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $profileIds),
            static fn (string $id): bool => $id !== ''
        )));
        if ($ids === []) {
            return [];
        }

        return AccountProfile::query()
            ->whereIn('_id', $ids)
            ->where('deleted_at', null)
            ->get()
            ->keyBy(fn (AccountProfile $profile): string => (string) $profile->_id)
            ->all();
    }

    /**
     * @param  array<int, string>  $userIds
     * @return array<string, AccountUser>
     */
    private function usersById(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $userIds),
            static fn (string $id): bool => $id !== ''
        )));
        if ($ids === []) {
            return [];
        }

        return AccountUser::query()
            ->whereIn('_id', $ids)
            ->get()
            ->keyBy(fn (AccountUser $user): string => (string) $user->_id)
            ->all();
    }

    /**
     * @param  array<int, AccountProfile>  $profiles
     * @return array<string, array<string, mixed>>
     */
    private function capabilitiesByProfileType(array $profiles): array
    {
        $types = collect($profiles)
            ->map(fn (AccountProfile $profile): string => trim((string) ($profile->profile_type ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($types === []) {
            return [];
        }

        return TenantProfileType::query()
            ->whereIn('type', $types)
            ->get()
            ->mapWithKeys(function (TenantProfileType $type): array {
                $capabilities = is_array($type->capabilities ?? null) ? $type->capabilities : [];

                return [(string) $type->type => $capabilities];
            })
            ->all();
    }

    private function isSelfTarget(
        AccountUser $viewer,
        ?AccountProfile $viewerProfile,
        ?AccountUser $targetUser,
        AccountProfile $targetProfile,
    ): bool {
        $viewerId = $this->userId($viewer);
        $targetUserId = $targetUser instanceof AccountUser ? $this->userId($targetUser) : '';
        if ($viewerId !== '' && $targetUserId !== '' && $viewerId === $targetUserId) {
            return true;
        }

        if (
            $viewerProfile instanceof AccountProfile
            && (string) $viewerProfile->_id !== ''
            && (string) $viewerProfile->_id === (string) $targetProfile->_id
        ) {
            return true;
        }

        return false;
    }

    private function profileOwner(AccountProfile $profile): ?AccountUser
    {
        $ownerId = $this->nullableString($profile->created_by);
        if ($ownerId === null || (string) ($profile->created_by_type ?? '') !== 'tenant') {
            return null;
        }

        $user = AccountUser::query()->find($ownerId);

        return $user instanceof AccountUser ? $user : null;
    }

    private function displayName(?AccountProfile $profile, ?AccountUser $user): ?string
    {
        return $this->nullableString($profile?->display_name)
            ?? $this->nullableString($user?->name)
            ?? $this->nullableString($profile?->slug);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function userId(AccountUser $user): string
    {
        return (string) ($user->getKey() ?? $user->_id ?? $user->getAuthIdentifier() ?? '');
    }
}
