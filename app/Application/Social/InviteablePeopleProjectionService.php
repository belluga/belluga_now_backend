<?php

declare(strict_types=1);

namespace App\Application\Social;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\Invites\Models\Tenants\InviteablePeopleProjection;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;

class InviteablePeopleProjectionService
{
    public function __construct(
        private readonly InviteablePeopleService $source,
    ) {}

    public function refreshForUser(AccountUser|string|null $viewer): void
    {
        $viewer = $viewer instanceof AccountUser
            ? $viewer
            : $this->accountUser((string) $viewer);
        if (! $viewer instanceof AccountUser) {
            return;
        }

        $ownerUserId = $this->userId($viewer);
        if ($ownerUserId === '') {
            return;
        }

        $seenProfileIds = [];
        foreach ($this->source->sourceInviteableItemsFor($viewer) as $item) {
            $profileId = $this->nullableString($item['receiver_account_profile_id'] ?? null);
            if ($profileId === null) {
                continue;
            }

            $seenProfileIds[] = $profileId;
            $this->upsertProjectionPayload($ownerUserId, $item);
        }

        $stale = InviteablePeopleProjection::query()
            ->where('owner_user_id', $ownerUserId);
        if ($seenProfileIds !== []) {
            $stale->whereNotIn('receiver_account_profile_id', array_values(array_unique($seenProfileIds)));
        }
        $stale->delete();
    }

    public function refreshProfileForUser(AccountUser|string|null $viewer, string $profileId): void
    {
        $viewer = $viewer instanceof AccountUser
            ? $viewer
            : $this->accountUser((string) $viewer);
        if (! $viewer instanceof AccountUser) {
            return;
        }

        $ownerUserId = $this->userId($viewer);
        $profileId = trim($profileId);
        if ($ownerUserId === '' || $profileId === '') {
            return;
        }

        $item = $this->source->sourceInviteableItemForProfile($viewer, $profileId);
        if ($item === null) {
            InviteablePeopleProjection::query()
                ->where('owner_user_id', $ownerUserId)
                ->where('receiver_account_profile_id', $profileId)
                ->delete();

            return;
        }

        $this->upsertProjectionPayload($ownerUserId, $item);
    }

    /**
     * @param  array<int, array{type:string,hash:string}>  $contacts
     * @param  array<string, array<string, mixed>>  $matches
     */
    public function refreshImportedContactsForUser(AccountUser|string|null $viewer, array $contacts, array $matches): void
    {
        $viewer = $viewer instanceof AccountUser
            ? $viewer
            : $this->accountUser((string) $viewer);
        if (! $viewer instanceof AccountUser) {
            return;
        }

        $ownerUserId = $this->userId($viewer);
        if ($ownerUserId === '') {
            return;
        }

        $contactHashes = $this->normalizedIds(array_map(
            static fn (array $contact): mixed => $contact['hash'] ?? null,
            $contacts,
        ));
        $profileIds = [];
        if ($contactHashes !== []) {
            $profileIds = InviteablePeopleProjection::query()
                ->where('owner_user_id', $ownerUserId)
                ->whereIn('contact_hash', $contactHashes)
                ->get()
                ->pluck('receiver_account_profile_id')
                ->all();
        }

        $matchedProfileIds = array_map(
            static fn (array $match): mixed => $match['receiver_account_profile_id'] ?? null,
            array_values($matches),
        );

        foreach ($this->normalizedIds([...$profileIds, ...$matchedProfileIds]) as $profileId) {
            $this->refreshProfileForUser($viewer, $profileId);
        }
    }

    /**
     * @param  array<int, mixed>  $ownerUserIds
     */
    public function refreshForUserIds(array $ownerUserIds): void
    {
        foreach ($this->normalizedIds($ownerUserIds) as $ownerUserId) {
            $this->refreshForUser($ownerUserId);
        }
    }

    /**
     * @param  array<int, mixed>  $hashes
     */
    public function refreshOwnersForContactHashes(string $type, array $hashes): void
    {
        $normalizedType = trim($type);
        if ($normalizedType === '') {
            return;
        }

        $normalizedHashes = $this->normalizedIds($hashes);
        if ($normalizedHashes === []) {
            return;
        }

        $directories = ContactHashDirectory::query()
            ->where('type', $normalizedType)
            ->whereIn('contact_hash', $normalizedHashes)
            ->whereNotNull('matched_user_id')
            ->get();

        foreach ($directories as $directory) {
            if (! $directory instanceof ContactHashDirectory) {
                continue;
            }

            $ownerUserId = $this->nullableString($directory->importing_user_id);
            $matchedUserId = $this->nullableString($directory->matched_user_id);
            $profileId = $matchedUserId === null ? null : $this->personalProfileIdForUserId($matchedUserId);
            if ($ownerUserId !== null && $profileId !== null) {
                $this->refreshProfileForUser($ownerUserId, $profileId);
            }
        }
    }

    public function refreshImpactedByFavorite(string $ownerUserId, string $targetProfileId): void
    {
        $this->refreshProfileForUser($ownerUserId, $targetProfileId);

        $targetProfile = AccountProfile::query()->find($targetProfileId);
        $targetOwnerId = $targetProfile instanceof AccountProfile
            ? $this->profileOwnerUserId($targetProfile)
            : null;
        $ownerProfileId = $this->personalProfileIdForUserId($ownerUserId);
        if ($targetOwnerId !== null && $ownerProfileId !== null) {
            $this->refreshProfileForUser($targetOwnerId, $ownerProfileId);
        }
    }

    public function refreshImpactedByProfile(AccountProfile $profile): void
    {
        $profileId = $this->nullableString($profile->getKey());
        if ($profileId === null) {
            return;
        }

        $ownerIds = [];
        $profileOwnerId = $this->profileOwnerUserId($profile);
        if ($profileOwnerId !== null) {
            $ownerIds[] = $profileOwnerId;

            $ownerIds = array_merge(
                $ownerIds,
                ContactHashDirectory::query()
                    ->where('matched_user_id', $profileOwnerId)
                    ->get()
                    ->pluck('importing_user_id')
                    ->all(),
            );

        }

        $ownerIds = array_merge(
            $ownerIds,
            FavoriteEdge::query()
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', $profileId)
                ->get()
                ->pluck('owner_user_id')
                ->all(),
        );

        $this->refreshProfileForOwners($ownerIds, $profileId);
    }

    public function refreshImpactedByUser(AccountUser $user): void
    {
        $userId = $this->userId($user);
        if ($userId === '') {
            return;
        }

        $profile = AccountProfile::query()
            ->where('created_by', $userId)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->where('deleted_at', null)
            ->first();
        if (! $profile instanceof AccountProfile) {
            return;
        }

        $profileId = $this->nullableString($profile->_id);
        if ($profileId === null) {
            return;
        }

        $ownerIds = [$userId];
        $ownerIds = array_merge(
            $ownerIds,
            ContactHashDirectory::query()
                ->where('matched_user_id', $userId)
                ->get()
                ->pluck('importing_user_id')
                ->all(),
        );

        $ownerIds = array_merge(
            $ownerIds,
            FavoriteEdge::query()
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', $profileId)
                ->get()
                ->pluck('owner_user_id')
                ->all(),
        );

        $this->refreshProfileForOwners($ownerIds, $profileId);
    }

    public function refreshImpactedByProfileType(TenantProfileType $type): void
    {
        $profileType = $this->nullableString($type->type);
        if ($profileType === null) {
            return;
        }

        AccountProfile::query()
            ->where('profile_type', $profileType)
            ->where('deleted_at', null)
            ->cursor()
            ->each(function (AccountProfile $profile): void {
                $profileOwnerId = $this->profileOwnerUserId($profile);
                $contactImportOwners = $profileOwnerId === null
                    ? []
                    : ContactHashDirectory::query()
                        ->where('matched_user_id', $profileOwnerId)
                        ->get()
                        ->pluck('importing_user_id')
                        ->all();
                $favoriteOwners = FavoriteEdge::query()
                    ->where('registry_key', 'account_profile')
                    ->where('target_type', 'account_profile')
                    ->where('target_id', (string) $profile->_id)
                    ->get()
                    ->pluck('owner_user_id')
                    ->all();

                $this->refreshProfileForOwners(array_values(array_filter([
                    $profileOwnerId,
                    ...$contactImportOwners,
                    ...$favoriteOwners,
                ])), (string) $profile->_id);
            });
    }

    /**
     * @return array{processed:int,projected:int}
     */
    public function backfillAllUsers(?int $limit = null): array
    {
        $query = AccountUser::query()
            ->where('deleted_at', null)
            ->orderBy('_id');
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $processed = 0;
        foreach ($query->cursor() as $user) {
            if (! $user instanceof AccountUser) {
                continue;
            }

            $this->refreshForUser($user);
            $processed++;
        }

        return [
            'processed' => $processed,
            'projected' => InviteablePeopleProjection::query()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsertProjectionPayload(string $ownerUserId, array $item): void
    {
        $profileId = $this->nullableString($item['receiver_account_profile_id'] ?? null);
        if ($ownerUserId === '' || $profileId === null) {
            return;
        }

        $now = Carbon::now();
        $timestamp = new UTCDateTime((int) $now->getTimestampMs());
        InviteablePeopleProjection::raw(
            fn ($collection) => $collection->updateOne(
                [
                    'owner_user_id' => $ownerUserId,
                    'receiver_account_profile_id' => $profileId,
                ],
                [
                    '$set' => [
                        'receiver_user_id' => $this->nullableString($item['user_id'] ?? null),
                        'display_name' => (string) ($item['display_name'] ?? ''),
                        'avatar_url' => $this->nullableString($item['avatar_url'] ?? null),
                        'cover_url' => $this->nullableString($item['cover_url'] ?? null),
                        'profile_type' => (string) ($item['profile_type'] ?? ''),
                        'profile_exposure_level' => (string) ($item['profile_exposure_level'] ?? 'aggregate_only'),
                        'inviteable_reasons' => $this->stringList($item['inviteable_reasons'] ?? []),
                        'source_tags' => $this->stringList($item['source_tags'] ?? []),
                        'is_inviteable' => (bool) ($item['is_inviteable'] ?? true),
                        'contact_hash' => $this->nullableString($item['contact_hash'] ?? null),
                        'contact_type' => $this->nullableString($item['contact_type'] ?? null),
                        'sort_name' => $this->sortName((string) ($item['display_name'] ?? '')),
                        'materialized_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    '$setOnInsert' => [
                        'owner_user_id' => $ownerUserId,
                        'receiver_account_profile_id' => $profileId,
                        'created_at' => $timestamp,
                    ],
                ],
                ['upsert' => true],
            )
        );
    }

    private function accountUser(string $userId): ?AccountUser
    {
        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }

        $user = AccountUser::query()->find($userId);

        return $user instanceof AccountUser ? $user : null;
    }

    private function profileOwnerUserId(AccountProfile $profile): ?string
    {
        if ((string) ($profile->created_by_type ?? '') !== 'tenant') {
            return null;
        }

        return $this->nullableString($profile->created_by);
    }

    /**
     * @param  array<int, mixed>  $ownerUserIds
     */
    private function refreshProfileForOwners(array $ownerUserIds, string $profileId): void
    {
        foreach ($this->normalizedIds($ownerUserIds) as $ownerUserId) {
            $this->refreshProfileForUser($ownerUserId, $profileId);
        }
    }

    private function personalProfileIdForUserId(string $userId): ?string
    {
        $userId = trim($userId);
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

        return $profile instanceof AccountProfile
            ? $this->nullableString($profile->_id)
            : null;
    }

    private function userId(AccountUser $user): string
    {
        return trim((string) ($user->_id ?? $user->getKey() ?? $user->getAuthIdentifier() ?? ''));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizedIds(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_unique(array_filter(array_map('strval', $value))))
            : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function sortName(string $value): string
    {
        $normalized = trim(mb_strtolower($value));

        return $normalized === '' ? '~' : $normalized;
    }
}
