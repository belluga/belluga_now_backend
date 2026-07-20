<?php

declare(strict_types=1);

namespace App\Application\Profiles;

use App\Application\AccountProfiles\AccountProfileReferenceCleanupService;
use App\Application\Auth\PhoneIdentityCoordinationStore;
use App\Application\Push\PushTopicMembershipService;
use App\Models\Landlord\Tenant;
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
 * Direct, current-principal erasure only. Profile lifecycle and reference
 * cleanup finish synchronously before authentication state is erased.
 */
final class CurrentTenantAccountDeletionService
{
    public function __construct(
        private readonly AccountProfileReferenceCleanupService $profileReferenceCleanup,
        private readonly PushTopicMembershipService $pushTopicMemberships,
        private readonly CurrentTenantAccountDeletionAccountGuard $accountGuard,
        private readonly PhoneIdentityCoordinationStore $phoneIdentityCoordination,
        private readonly CurrentTenantAccountDeletionAttemptService $deletionAttempts,
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
        $attempt = null;

        try {
            $this->sleepBeforeCriticalMutationHook();
            $attempt = $this->deletionAttempts->captureOrResume($userId);

            $personalProfileIds = $this->attemptProfileIds($attempt);
            $candidateAccountIds = $this->attemptAccountIds($attempt);
            if ((string) ($attempt['phase'] ?? '') === 'captured_and_fenced') {
                $this->phoneIdentityCoordination->assertStillOwned($lease);
                $this->profileReferenceCleanup->cleanSurvivingReferences($userId, $personalProfileIds);
                $attempt = $this->deletionAttempts->transition(
                    $attempt,
                    'captured_and_fenced',
                    'references_cleaned',
                );
            }
            if ((string) ($attempt['phase'] ?? '') === 'references_cleaned') {
                $this->accountGuard->eraseRevalidatedPersonalGraph(
                    $userId,
                    $personalProfileIds,
                    $candidateAccountIds,
                    $attempt,
                );
                $attempt = $this->deletionAttempts->transition($attempt, 'references_cleaned', 'terminalized');
            }
            if ((string) ($attempt['phase'] ?? '') === 'terminalized') {
                $attempt = $this->deletionAttempts->purgeFrozenMediaDescriptors($attempt);
                $attempt = $this->deletionAttempts->transition($attempt, 'terminalized', 'media_purged');
            }
            if ((string) ($attempt['phase'] ?? '') === 'media_purged') {
                $this->eraseUserOwnedTenantData($userId, $phoneHashes, $personalProfileIds);
                $attempt = $this->deletionAttempts->transition($attempt, 'media_purged', 'completed');
            }

            $this->phoneIdentityCoordination->assertStillOwned($lease);
            $this->eraseAuthenticationState($user, $userId);
            AccountUser::withoutEvents(static function () use ($userId): void {
                AccountUser::query()
                    ->where('_id', $userId)
                    ->forceDelete();
            });
        } catch (\Throwable $exception) {
            try {
                $attempt = $this->deletionAttempts->recordFailure($attempt, $exception);
            } catch (\Throwable $recordingException) {
                report($recordingException);
            }

            throw $exception;
        } finally {
            $this->deletionAttempts->releaseClaim($attempt);
            $this->phoneIdentityCoordination->release($lease);
        }
    }

    /** @param array<string, mixed> $attempt
     * @return list<string>
     */
    private function attemptProfileIds(array $attempt): array
    {
        return $this->normalizedStrings(array_map(
            static fn (mixed $descriptor): mixed => is_array($descriptor) ? ($descriptor['profile_id'] ?? null) : null,
            (array) ($attempt['profile_descriptors'] ?? []),
        ));
    }

    /** @param array<string, mixed> $attempt
     * @return list<string>
     */
    private function attemptAccountIds(array $attempt): array
    {
        return $this->normalizedStrings(array_map(
            static fn (mixed $descriptor): mixed => is_array($descriptor) ? ($descriptor['account_id'] ?? null) : null,
            (array) ($attempt['account_descriptors'] ?? []),
        ));
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
