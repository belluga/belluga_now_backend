<?php

declare(strict_types=1);

namespace App\Application\Profiles;

use App\Application\AccountProfiles\AccountProfileNestedGroupMemberStore;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\Auth\PhoneIdentityCoordinationStore;
use App\Application\Push\PushTopicMembershipService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\AttendanceCommitment;
use App\Models\Tenants\ContactGroup;
use App\Models\Tenants\IdentityMergeAudit;
use App\Models\Tenants\MergedAccountSnapshot;
use App\Models\Tenants\PhoneOtpChallenge;
use App\Models\Tenants\ProximityPreference;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\Invites\Models\Tenants\InviteablePeopleProjection;
use Belluga\Invites\Models\Tenants\InviteCommandIdempotency;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteFeedProjection;
use Belluga\Invites\Models\Tenants\InviteOutboxEvent;
use Belluga\Invites\Models\Tenants\InviteShareCode;
use Belluga\PushHandler\Models\Tenants\PushDeliveryLog;
use Belluga\PushHandler\Models\Tenants\PushDevice;
use Belluga\PushHandler\Models\Tenants\PushMessageAction;
use Illuminate\Support\Facades\DB;

/**
 * Direct, current-principal erasure only. It intentionally creates neither
 * lifecycle records nor asynchronous deletion work.
 */
final class CurrentTenantAccountDeletionService
{
    public function __construct(
        private readonly AccountProfileMediaService $profileMedia,
        private readonly PushTopicMembershipService $pushTopicMemberships,
        private readonly CurrentTenantAccountDeletionAccountGuard $accountGuard,
        private readonly PhoneIdentityCoordinationStore $phoneIdentityCoordination,
    ) {}

    public function delete(Tenant $tenant, AccountUser $user): void
    {
        $tenantId = trim((string) $tenant->getKey());
        $userId = trim((string) $user->getKey());
        if ($tenantId === '' || $userId === '') {
            throw new \RuntimeException('Current tenant identity is not resolvable for deletion.');
        }

        $phoneHashes = $this->normalizedStrings((array) ($user->phone_hashes ?? []));
        $lease = $this->phoneIdentityCoordination->acquire($phoneHashes, 'current_account_delete');

        try {
            $this->sleepBeforeCriticalMutationHook();

            $personalProfiles = $this->personalProfiles($userId);
            $personalProfileIds = $this->profileIds($personalProfiles);
            $deletableAccountIds = $this->deletablePersonalAccountIds($userId, $personalProfiles);

            $this->phoneIdentityCoordination->assertStillOwned($lease);
            $this->eraseProfileMedia($personalProfiles);
            $this->eraseExternalProfileReferences($personalProfileIds);
            $this->eraseUserOwnedTenantData($userId, $phoneHashes, $personalProfileIds);
            $this->eraseAuthenticationState($user, $userId);
            $this->accountGuard->eraseRevalidatedPersonalGraph(
                $userId,
                $personalProfileIds,
                $deletableAccountIds,
            );

            $this->phoneIdentityCoordination->assertStillOwned($lease);
            AccountUser::withoutEvents(static function () use ($userId): void {
                AccountUser::query()
                    ->where('_id', $userId)
                    ->forceDelete();
            });
        } finally {
            $this->phoneIdentityCoordination->release($lease);
        }
    }

    /**
     * @return array<int, AccountProfile>
     */
    private function personalProfiles(string $userId): array
    {
        return AccountProfile::query()
            ->where('created_by', $userId)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->whereNull('deleted_at')
            ->orderBy('_id')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, AccountProfile>  $profiles
     * @return array<int, string>
     */
    private function profileIds(array $profiles): array
    {
        return $this->normalizedStrings(array_map(
            static fn (AccountProfile $profile): string => (string) $profile->getKey(),
            $profiles,
        ));
    }

    /**
     * @param  array<int, AccountProfile>  $personalProfiles
     * @return array<int, string>
     */
    private function deletablePersonalAccountIds(string $userId, array $personalProfiles): array
    {
        $candidateProfileIds = $this->profileIds($personalProfiles);
        $candidateAccountIds = $this->normalizedStrings(array_map(
            static fn (AccountProfile $profile): string => (string) ($profile->account_id ?? ''),
            $personalProfiles,
        ));

        if ($candidateAccountIds === []) {
            return [];
        }

        // These three bounded, owner-keyed reads deliberately replace the former
        // per-candidate account/profile/member lookup loop. Duplicate or malformed
        // profile candidates remain safe: ambiguity preserves the account.
        $ownedAccountIds = Account::query()
            ->whereIn('_id', $candidateAccountIds)
            ->where('created_by', $userId)
            ->where('created_by_type', 'tenant')
            ->where('ownership_state', 'unmanaged')
            ->pluck('id')
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        if ($ownedAccountIds === []) {
            return [];
        }

        $liveProfileIdsByAccount = [];
        AccountProfile::query()
            ->whereIn('account_id', $ownedAccountIds)
            ->whereNull('deleted_at')
            ->orderBy('_id')
            ->get(['_id', 'account_id'])
            ->each(function (AccountProfile $profile) use (&$liveProfileIdsByAccount): void {
                $accountId = trim((string) $profile->account_id);
                $profileId = trim((string) $profile->getKey());
                if ($accountId !== '' && $profileId !== '') {
                    $liveProfileIdsByAccount[$accountId][] = $profileId;
                }
            });

        $memberIdsByAccount = [];
        AccountUser::query()
            ->whereIn('account_roles.account_id', $ownedAccountIds)
            ->orderBy('_id')
            ->get(['_id', 'account_roles'])
            ->each(function (AccountUser $member) use (&$memberIdsByAccount, $ownedAccountIds): void {
                $memberId = trim((string) $member->getKey());
                if ($memberId === '') {
                    return;
                }

                foreach ((array) ($member->account_roles ?? []) as $role) {
                    $accountId = trim((string) (is_array($role) ? ($role['account_id'] ?? '') : ''));
                    if ($accountId !== '' && in_array($accountId, $ownedAccountIds, true)) {
                        $memberIdsByAccount[$accountId][] = $memberId;
                    }
                }
            });

        return $this->normalizedStrings(array_filter(
            $ownedAccountIds,
            function (string $accountId) use ($candidateProfileIds, $liveProfileIdsByAccount, $memberIdsByAccount, $userId): bool {
                $liveProfileIds = $this->normalizedStrings($liveProfileIdsByAccount[$accountId] ?? []);
                $memberIds = $this->normalizedStrings($memberIdsByAccount[$accountId] ?? []);

                return $liveProfileIds !== []
                    && count(array_diff($liveProfileIds, $candidateProfileIds)) === 0
                    && ($memberIds === [] || (
                        count($memberIds) === 1 && hash_equals($userId, $memberIds[0])
                    ));
            },
        ));
    }

    /**
     * @param  array<int, AccountProfile>  $profiles
     */
    private function eraseProfileMedia(array $profiles): void
    {
        foreach ($profiles as $profile) {
            $this->profileMedia->removeAllUploads($profile);
        }
    }

    /**
     * @param  array<int, string>  $profileIds
     */
    private function eraseExternalProfileReferences(array $profileIds): void
    {
        if ($profileIds === []) {
            return;
        }

        $profiles = $this->tenantCollection('account_profiles');
        $profiles->updateMany(
            ['contact_source_account_profile_id' => ['$in' => $profileIds]],
            [
                '$set' => [
                    'contact_mode' => 'own',
                    'contact_source_account_profile_id' => null,
                ],
            ],
        );

        $profiles->updateMany(
            ['nested_profile_groups.account_profile_ids' => ['$in' => $profileIds]],
            [[
                '$set' => [
                    'nested_profile_groups' => [
                        '$map' => [
                            'input' => ['$ifNull' => ['$nested_profile_groups', []]],
                            'as' => 'group',
                            'in' => [
                                '$mergeObjects' => [
                                    '$$group',
                                    [
                                        'account_profile_ids' => [
                                            '$filter' => [
                                                'input' => ['$ifNull' => ['$$group.account_profile_ids', []]],
                                                'as' => 'profile_id',
                                                'cond' => [
                                                    '$not' => [[
                                                        '$in' => ['$$profile_id', $profileIds],
                                                    ]],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]],
        );

        $memberRows = $this->tenantCollection(AccountProfileNestedGroupMemberStore::COLLECTION);
        $affectedParentIds = $this->normalizedStrings(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $memberRows->distinct('parent_profile_id', [
                'doc_type' => 'member_row',
                'member_profile_id' => ['$in' => $profileIds],
            ]),
        ));

        if ($affectedParentIds === []) {
            return;
        }

        $memberRows->deleteMany([
            'doc_type' => 'member_row',
            'member_profile_id' => ['$in' => $profileIds],
        ]);

        $groupCountsByParent = [];
        foreach ($memberRows->find(
            [
                'doc_type' => 'member_row',
                'parent_profile_id' => ['$in' => $affectedParentIds],
            ],
            ['projection' => ['parent_profile_id' => 1, 'group_id' => 1]],
        ) as $row) {
            $parentProfileId = trim((string) ($row['parent_profile_id'] ?? ''));
            $groupId = trim((string) ($row['group_id'] ?? ''));
            if ($parentProfileId === '' || $groupId === '') {
                continue;
            }

            $groupCountsByParent[$parentProfileId][$groupId] = ($groupCountsByParent[$parentProfileId][$groupId] ?? 0) + 1;
        }

        foreach (AccountProfile::withTrashed()->whereIn('_id', $affectedParentIds)->get() as $parentProfile) {
            if (! $parentProfile instanceof AccountProfile) {
                continue;
            }

            $nestedGroups = [];
            foreach ((array) ($parentProfile->nested_profile_groups ?? []) as $index => $group) {
                if (! is_array($group)) {
                    $nestedGroups[] = $group;

                    continue;
                }

                $groupId = trim((string) ($group['id'] ?? $group['key'] ?? ''));
                if ($groupId === '') {
                    $nestedGroups[] = $group;

                    continue;
                }

                unset($group['account_profile_ids']);
                $group['order'] = isset($group['order']) ? (int) $group['order'] : $index;
                $group['member_count'] = (int) ($groupCountsByParent[(string) $parentProfile->getKey()][$groupId] ?? 0);
                $nestedGroups[] = $group;
            }

            $profiles->updateOne(
                ['_id' => $parentProfile->getKey()],
                ['$set' => ['nested_profile_groups' => $nestedGroups]],
            );
        }
    }

    /**
     * @param  array<int, string>  $phoneHashes
     * @param  array<int, string>  $profileIds
     */
    private function eraseUserOwnedTenantData(
        string $userId,
        array $phoneHashes,
        array $profileIds,
    ): void {
        FavoriteEdge::query()->where('owner_user_id', $userId)->delete();
        ContactGroup::query()->where('owner_user_id', $userId)->delete();
        ProximityPreference::query()->where('owner_user_id', $userId)->delete();
        AttendanceCommitment::query()->where('user_id', $userId)->delete();
        PushMessageAction::query()->where('user_id', $userId)->delete();

        ContactHashDirectory::query()->where('importing_user_id', $userId)->delete();
        $this->tenantCollection('contact_hash_directory')->updateMany(
            [
                'matched_user_id' => $userId,
                'importing_user_id' => ['$ne' => $userId],
            ],
            [
                '$unset' => [
                    'matched_user_id' => '',
                    'match_snapshot' => '',
                ],
            ],
        );

        $phoneOtpSelector = ['anonymous_user_ids' => $userId];
        if ($phoneHashes !== []) {
            $phoneOtpSelector = [
                '$or' => [
                    ['phone_hash' => ['$in' => $phoneHashes]],
                    ['anonymous_user_ids' => $userId],
                ],
            ];
        }
        $this->tenantCollection((new PhoneOtpChallenge)->getTable())->deleteMany($phoneOtpSelector);
        IdentityMergeAudit::query()
            ->where('canonical_user_id', $userId)
            ->orWhere('merged_source_ids', $userId)
            ->delete();
        MergedAccountSnapshot::query()
            ->where('source_user_id', $userId)
            ->orWhere('merged_into', $userId)
            ->delete();
        $this->tenantCollection('account_users')->updateMany(
            ['merged_source_ids' => $userId],
            ['$pull' => ['merged_source_ids' => $userId]],
        );

        $this->eraseInviteState($userId, $profileIds);
        $this->erasePushState($userId);
    }

    /**
     * @param  array<int, string>  $profileIds
     */
    private function eraseInviteState(string $userId, array $profileIds): void
    {
        $edgeQuery = InviteEdge::query()
            ->where('issued_by_user_id', $userId)
            ->orWhere('receiver_user_id', $userId);
        if ($profileIds !== []) {
            $edgeQuery->orWhereIn('receiver_account_profile_id', $profileIds);
        }
        $edgeQuery->delete();

        InviteShareCode::query()->where('issued_by_user_id', $userId)->delete();
        InviteFeedProjection::query()->where('receiver_user_id', $userId)->delete();
        InviteOutboxEvent::query()->where('receiver_user_id', $userId)->delete();
        InviteCommandIdempotency::query()->where('actor_user_id', $userId)->delete();

        $projectionQuery = InviteablePeopleProjection::query()
            ->where('owner_user_id', $userId)
            ->orWhere('receiver_user_id', $userId);
        if ($profileIds !== []) {
            $projectionQuery->orWhereIn('receiver_account_profile_id', $profileIds);
        }
        $projectionQuery->delete();
    }

    private function erasePushState(string $userId): void
    {
        $tokens = PushDevice::query()
            ->where('account_user_id', $userId)
            ->pluck('push_token')
            ->map(static fn (mixed $token): string => trim((string) $token))
            ->filter(static fn (string $token): bool => $token !== '')
            ->unique()
            ->values()
            ->all();

        try {
            $this->pushTopicMemberships->unsubscribeTokensFromAll($tokens);
        } catch (\Throwable) {
            // Provider cleanup is explicitly best effort and never leaves a job payload.
        }

        $tokenHashes = array_values(array_unique(array_map(
            static fn (string $token): string => hash('sha256', $token),
            $tokens,
        )));
        if ($tokenHashes !== []) {
            PushDeliveryLog::query()->whereIn('token_hash', $tokenHashes)->delete();
        }
        PushDevice::query()->where('account_user_id', $userId)->delete();
    }

    private function eraseAuthenticationState(
        AccountUser $user,
        string $userId,
    ): void {
        $user->tokens()->delete();

        DB::connection('landlord')
            ->getMongoDB()
            ->selectCollection('password_reset_tokens')
            ->deleteMany([
                'broker' => 'tenant_users',
                'user_id_string' => $userId,
            ]);
    }

    private function tenantCollection(string $name): \MongoDB\Collection
    {
        return DB::connection('tenant')->getMongoDB()->selectCollection($name);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizedStrings(array $values): array
    {
        return collect($values)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function sleepBeforeCriticalMutationHook(): void
    {
        $delayMilliseconds = (int) (getenv('BELLUGA_TEST_CURRENT_ACCOUNT_DELETE_BEFORE_MUTATION_SLEEP_MS') ?: 0);
        if ($delayMilliseconds <= 0) {
            return;
        }

        usleep($delayMilliseconds * 1000);
    }
}
