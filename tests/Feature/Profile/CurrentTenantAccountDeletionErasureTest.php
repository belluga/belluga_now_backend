<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\Profiles\CurrentTenantAccountDeletionAttemptService;
use App\Jobs\Push\UnsubscribePushTokensFromAllTopicsJob;
use App\Models\Landlord\PersonalAccessToken;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class CurrentTenantAccountDeletionErasureTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->ensureSystemInitialized();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
    }

    public function test_direct_current_user_records_are_erased_and_shared_records_survive(): void
    {
        Storage::fake('public');
        Queue::fake();

        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Delete Target',
            'phones' => ['+5527999990201'],
        ]);
        $other = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Surviving User',
            'phones' => ['+5527999990202'],
        ]);
        $targetId = (string) $target->_id;
        $otherId = (string) $other->_id;

        $personalAccount = Account::create([
            'name' => 'Target Personal Account',
            'slug' => 'target-personal-account',
            'document' => ['type' => 'cpf', 'number' => 'PERSONAL-'.$targetId],
            'ownership_state' => 'unmanaged',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $personalProfile = AccountProfile::create([
            'account_id' => (string) $personalAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Delete Target',
            'slug' => 'delete-target-personal',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $secondPersonalAccount = Account::create([
            'name' => 'Target Second Personal Account',
            'slug' => 'target-second-personal-account',
            'document' => ['type' => 'cpf', 'number' => 'PERSONAL-SECOND-'.$targetId],
            'ownership_state' => 'unmanaged',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $secondPersonalProfile = AccountProfile::create([
            'account_id' => (string) $secondPersonalAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Delete Target Second Personal',
            'slug' => 'delete-target-second-personal',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
            'gallery_groups' => [[
                'items' => [['item_id' => 'delete-gallery-item']],
            ]],
        ]);
        $malformedPersonalProfile = AccountProfile::create([
            'profile_type' => 'personal',
            'display_name' => 'Malformed Delete Candidate',
            'slug' => 'malformed-delete-candidate',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);

        $mediaDirectory = sprintf(
            'tenants/%s/account_profiles/%s',
            (string) Tenant::current()->slug,
            (string) $secondPersonalProfile->_id,
        );
        Storage::disk('public')->put("{$mediaDirectory}/avatar.png", 'avatar');
        Storage::disk('public')->put("{$mediaDirectory}/cover.jpg", 'cover');
        Storage::disk('public')->put("{$mediaDirectory}/gallery-item-delete-gallery-item.png", 'gallery');

        $sharedAccount = Account::create([
            'name' => 'Shared Account',
            'slug' => 'shared-account-delete-test',
            'document' => ['type' => 'cpf', 'number' => 'SHARED-'.$targetId],
            'ownership_state' => 'unmanaged',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $sharedProfile = AccountProfile::create([
            'account_id' => (string) $sharedAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Shared Profile',
            'slug' => 'shared-profile-delete-test',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $other->account_roles = [[
            'account_id' => (string) $sharedAccount->_id,
            'name' => 'Member',
            'slug' => 'member',
            'permissions' => [],
        ]];
        $other->save();

        FavoriteEdge::create(['owner_user_id' => $targetId, 'registry_key' => 'account_profile', 'target_type' => 'account_profile', 'target_id' => (string) $sharedProfile->_id]);
        FavoriteEdge::create(['owner_user_id' => $otherId, 'registry_key' => 'account_profile', 'target_type' => 'account_profile', 'target_id' => (string) $sharedProfile->_id]);
        ContactGroup::create(['owner_user_id' => $targetId, 'name' => 'Target group']);
        ProximityPreference::create(['owner_user_id' => $targetId, 'max_distance_meters' => 5000]);
        AttendanceCommitment::create(['user_id' => $targetId, 'event_id' => 'event-1', 'occurrence_id' => 'occurrence-1', 'kind' => 'attendance', 'status' => 'confirmed']);
        ContactHashDirectory::create(['importing_user_id' => $targetId, 'contact_hash' => 'target-owned-hash', 'type' => 'phone']);
        $survivingContact = ContactHashDirectory::create([
            'importing_user_id' => $otherId,
            'contact_hash' => 'target-matched-hash',
            'type' => 'phone',
            'matched_user_id' => $targetId,
            'match_snapshot' => ['display_name' => 'Delete Target'],
        ]);
        PhoneOtpChallenge::create(['phone' => '+5527999990201', 'phone_hash' => $target->phone_hashes[0], 'code_hash' => 'hash', 'status' => 'verified']);
        IdentityMergeAudit::create(['canonical_user_id' => $targetId, 'merged_source_ids' => ['source-1']]);
        MergedAccountSnapshot::create(['source_user_id' => $targetId, 'merged_into' => 'other-user']);
        InviteEdge::create(['issued_by_user_id' => $targetId, 'receiver_user_id' => $otherId, 'receiver_account_profile_id' => (string) $personalProfile->_id]);
        InviteShareCode::create(['code' => 'delete-test-code', 'issued_by_user_id' => $targetId]);
        InviteFeedProjection::create(['receiver_user_id' => $targetId, 'group_key' => 'delete-test']);
        InviteOutboxEvent::create(['receiver_user_id' => $targetId, 'topic' => 'delete-test', 'status' => 'pending', 'dedupe_key' => 'delete-test']);
        InviteCommandIdempotency::create(['command' => 'delete-test', 'actor_user_id' => $targetId, 'idempotency_key' => 'delete-test-key', 'command_fingerprint' => 'fingerprint']);
        InviteablePeopleProjection::create(['owner_user_id' => $otherId, 'receiver_user_id' => $targetId, 'receiver_account_profile_id' => (string) $personalProfile->_id, 'display_name' => 'Delete Target']);

        $device = PushDevice::create([
            'account_user_id' => $targetId,
            'device_id' => 'delete-test-device',
            'platform' => 'ios',
            'push_token' => 'delete-test-push-token',
            'is_active' => true,
        ]);
        PushDeliveryLog::create(['token_hash' => hash('sha256', $device->push_token), 'status' => 'sent']);
        PushMessageAction::create(['user_id' => $targetId, 'action' => 'opened', 'idempotency_key' => 'delete-test-action']);
        $target->createToken('delete-test-token', []);

        $this->assertSame((string) $personalAccount->_id, (string) $personalProfile->account_id);
        $this->assertTrue(
            Account::query()
                ->where('_id', (string) $personalProfile->account_id)
                ->where('created_by', $targetId)
                ->where('created_by_type', 'tenant')
                ->where('ownership_state', 'unmanaged')
                ->exists(),
        );
        $this->assertSame(
            [(string) $personalProfile->_id],
            AccountProfile::query()
                ->where('account_id', (string) $personalAccount->_id)
                ->whereNull('deleted_at')
                ->orderBy('_id')
                ->pluck('id')
                ->map(static fn (mixed $id): string => trim((string) $id))
                ->values()
                ->all(),
        );
        $this->assertSame(
            [],
            AccountUser::query()
                ->where('account_roles.account_id', (string) $personalAccount->_id)
                ->orderBy('_id')
                ->pluck('id')
                ->map(static fn (mixed $id): string => trim((string) $id))
                ->values()
                ->all(),
        );

        Sanctum::actingAs($target, ['*']);

        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertNoContent();

        $this->assertNull(AccountUser::withTrashed()->find($targetId));
        $this->assertNotNull(AccountUser::query()->find($otherId));
        $this->assertNull(AccountProfile::withTrashed()->find((string) $personalProfile->_id));
        $this->assertNull(Account::withTrashed()->find((string) $personalAccount->_id));
        $this->assertNull(AccountProfile::withTrashed()->find((string) $secondPersonalProfile->_id));
        $this->assertNull(Account::withTrashed()->find((string) $secondPersonalAccount->_id));
        $this->assertNull(AccountProfile::withTrashed()->find((string) $malformedPersonalProfile->_id));
        $this->assertNull(AccountProfile::withTrashed()->find((string) $sharedProfile->_id));
        $this->assertNotNull(Account::query()->find((string) $sharedAccount->_id));
        $sharedAccount->refresh();
        $this->assertNull($sharedAccount->account_profile_deletion_gate);
        $this->assertTrue(FavoriteEdge::query()->where('owner_user_id', $otherId)->exists());

        $this->assertFalse(FavoriteEdge::query()->where('owner_user_id', $targetId)->exists());
        $this->assertFalse(ContactGroup::query()->where('owner_user_id', $targetId)->exists());
        $this->assertFalse(ProximityPreference::query()->where('owner_user_id', $targetId)->exists());
        $this->assertFalse(AttendanceCommitment::query()->where('user_id', $targetId)->exists());
        $this->assertFalse(ContactHashDirectory::query()->where('importing_user_id', $targetId)->exists());
        $this->assertFalse(PhoneOtpChallenge::query()->where('phone_hash', $target->phone_hashes[0])->exists());
        $this->assertFalse(IdentityMergeAudit::query()->where('canonical_user_id', $targetId)->exists());
        $this->assertFalse(MergedAccountSnapshot::query()->where('source_user_id', $targetId)->exists());
        $this->assertFalse(InviteEdge::query()->where('issued_by_user_id', $targetId)->exists());
        $this->assertFalse(InviteShareCode::query()->where('issued_by_user_id', $targetId)->exists());
        $this->assertFalse(InviteFeedProjection::query()->where('receiver_user_id', $targetId)->exists());
        $this->assertFalse(InviteOutboxEvent::query()->where('receiver_user_id', $targetId)->exists());
        $this->assertFalse(InviteCommandIdempotency::query()->where('actor_user_id', $targetId)->exists());
        $this->assertFalse(InviteablePeopleProjection::query()->where('receiver_user_id', $targetId)->exists());
        $this->assertFalse(PushDevice::query()->where('account_user_id', $targetId)->exists());
        $this->assertFalse(PushDeliveryLog::query()->where('token_hash', hash('sha256', $device->push_token))->exists());
        $this->assertFalse(PushMessageAction::query()->where('user_id', $targetId)->exists());
        $this->assertFalse(PersonalAccessToken::query()->where('tokenable_id', $targetId)->exists());
        $tenantDatabase = DB::connection('tenant')->getDatabase();

        $this->assertSame(
            1,
            $tenantDatabase
                ->selectCollection('account_profile_deletion_attempts')
                ->countDocuments([
                    '_id' => $targetId,
                    'profile_descriptors.media_descriptors.purged_at' => ['$exists' => true],
                ]),
        );

        $this->assertNull($tenantDatabase->selectCollection('push_devices')->findOne([
            'account_user_id' => $targetId,
        ]));
        $this->assertNull($tenantDatabase->selectCollection('push_delivery_logs')->findOne([
            'token_hash' => hash('sha256', $device->push_token),
        ]));
        Storage::disk('public')->assertMissing("{$mediaDirectory}/avatar.png");
        Storage::disk('public')->assertMissing("{$mediaDirectory}/cover.jpg");
        Storage::disk('public')->assertMissing("{$mediaDirectory}/gallery-item-delete-gallery-item.png");
        Queue::assertNotPushed(UnsubscribePushTokensFromAllTopicsJob::class);

        $survivingContact->refresh();
        $this->assertNull($survivingContact->matched_user_id);
        $this->assertNull($survivingContact->match_snapshot);
    }

    public function test_current_account_deletion_cleans_surviving_contact_mirrors_through_the_profile_outbox(): void
    {
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Mirror Delete Target',
            'phones' => ['+5527999990211'],
        ]);
        $targetId = (string) $target->_id;
        $targetAccount = Account::create([
            'name' => 'Mirror Target Account',
            'slug' => 'mirror-target-account-'.$targetId,
            'document' => ['type' => 'cpf', 'number' => 'MIRROR-TARGET-'.$targetId],
            'ownership_state' => 'unmanaged',
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);
        $targetProfile = AccountProfile::create([
            'account_id' => (string) $targetAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Mirror Delete Target',
            'slug' => 'mirror-delete-target-'.$targetId,
            'created_by' => $targetId,
            'created_by_type' => 'tenant',
        ]);

        $survivor = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Mirror Survivor',
            'phones' => ['+5527999990212'],
        ]);
        $survivorId = (string) $survivor->_id;
        $survivorAccount = Account::create([
            'name' => 'Mirror Survivor Account',
            'slug' => 'mirror-survivor-account-'.$survivorId,
            'document' => ['type' => 'cpf', 'number' => 'MIRROR-SURVIVOR-'.$survivorId],
            'ownership_state' => 'unmanaged',
            'created_by' => $survivorId,
            'created_by_type' => 'tenant',
        ]);
        $nestedMemberAccount = Account::create([
            'name' => 'Nested Member Account',
            'slug' => 'nested-member-account-'.$survivorId,
            'document' => ['type' => 'cpf', 'number' => 'NESTED-MEMBER-'.$survivorId],
            'ownership_state' => 'unmanaged',
            'created_by' => $survivorId,
            'created_by_type' => 'tenant',
        ]);
        $survivingNestedMember = AccountProfile::create([
            'account_id' => (string) $nestedMemberAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Surviving Nested Member',
            'slug' => 'surviving-nested-member-'.$survivorId,
            'created_by' => $survivorId,
            'created_by_type' => 'tenant',
        ]);
        $survivorProfile = AccountProfile::create([
            'account_id' => (string) $survivorAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Mirror Survivor',
            'slug' => 'mirror-survivor-'.$survivorId,
            'created_by' => $survivorId,
            'created_by_type' => 'tenant',
            'contact_mode' => 'mirrored_account_profile',
            'contact_source_account_profile_id' => (string) $targetProfile->_id,
            'nested_profile_groups' => [[
                'id' => 'linked-profiles',
                'label' => 'Linked profiles',
                'account_profile_ids' => [
                    (string) $targetProfile->_id,
                    (string) $survivingNestedMember->_id,
                ],
            ]],
        ]);

        Sanctum::actingAs($target, ['*']);
        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertNoContent();

        $survivorProfile->refresh();
        $this->assertSame('own', $survivorProfile->contact_mode);
        $this->assertNull($survivorProfile->contact_source_account_profile_id);
        $this->assertSame(
            [(string) $survivingNestedMember->_id],
            $survivorProfile->nested_profile_groups[0]['account_profile_ids'] ?? [],
        );

        $commandId = "current-account-delete:{$targetId}:reference-cleanup:".(string) $survivorProfile->_id;
        $database = DB::connection('tenant')->getDatabase();
        $receipt = $database
            ->selectCollection('account_profile_command_receipts')
            ->findOne(['_id' => $commandId]);
        $this->assertNotNull($receipt);

        $outbox = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['_id' => $receipt['outbox_event_id'] ?? '']);
        $this->assertNotNull($outbox);
        $this->assertSame('upsert', $outbox['operation'] ?? null);
        $this->assertSame('completed', $outbox['delivery_state'] ?? null);
    }

    public function test_deletion_attempt_resumes_media_purge_after_external_delete_before_descriptor_mark(): void
    {
        Storage::fake('public');

        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Restartable Media Target',
            'phones' => ['+5527999990213'],
        ]);
        $profile = AccountProfile::create([
            'profile_type' => 'personal',
            'display_name' => 'Restartable Media Target',
            'slug' => 'restartable-media-target-'.(string) $user->_id,
            'created_by' => (string) $user->_id,
            'created_by_type' => 'tenant',
        ]);
        $path = sprintf(
            'tenants/%s/account_profiles/%s/avatar.png',
            (string) Tenant::current()->slug,
            (string) $profile->_id,
        );
        Storage::disk('public')->put($path, 'restartable-avatar');

        $attempts = app(CurrentTenantAccountDeletionAttemptService::class);
        $attempt = $attempts->captureOrResume((string) $user->_id);
        $attempt = $attempts->transition($attempt, 'captured_and_fenced', 'references_cleaned');
        $attempt = $attempts->transition($attempt, 'references_cleaned', 'terminalized');
        $frozenDescriptors = $attempts->frozenMediaDescriptors($attempt);
        $this->assertCount(1, $frozenDescriptors);

        app(AccountProfileMediaService::class)->purgeFrozenDeletionMediaDescriptors($frozenDescriptors);
        Storage::disk('public')->assertMissing($path);

        $attempt = $attempts->recordFailure($attempt, new \RuntimeException('simulated process interruption'));
        $this->assertSame('simulated process interruption', $attempt['last_error']['message'] ?? null);
        $attempts->releaseClaim($attempt);

        $resumed = $attempts->captureOrResume((string) $user->_id);
        $resumed = $attempts->purgeFrozenMediaDescriptors($resumed);
        $this->assertSame([], $attempts->frozenMediaDescriptors($resumed));

        $resumed = $attempts->transition($resumed, 'terminalized', 'media_purged');
        $resumed = $attempts->transition($resumed, 'media_purged', 'completed');
        $this->assertSame('completed', $resumed['phase'] ?? null);
        $this->assertNull($resumed['last_error'] ?? null);
        $this->assertSame(2, (int) ($resumed['attempts'] ?? 0));
    }

    public function test_current_account_deletion_rejects_a_partial_account_gate_claim(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Gate Claim Target',
            'phones' => ['+5527999990214'],
        ]);
        $userId = (string) $user->_id;
        $firstAccount = Account::create([
            'name' => 'Gate Claim First Account',
            'slug' => 'gate-claim-first-'.$userId,
            'document' => ['type' => 'cpf', 'number' => 'GATE-CLAIM-FIRST-'.$userId],
            'ownership_state' => 'unmanaged',
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        $blockedAccount = Account::create([
            'name' => 'Gate Claim Blocked Account',
            'slug' => 'gate-claim-blocked-'.$userId,
            'document' => ['type' => 'cpf', 'number' => 'GATE-CLAIM-BLOCKED-'.$userId],
            'ownership_state' => 'unmanaged',
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        $firstProfile = AccountProfile::create([
            'account_id' => (string) $firstAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Gate Claim First Profile',
            'slug' => 'gate-claim-first-profile-'.$userId,
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        $blockedProfile = AccountProfile::create([
            'account_id' => (string) $blockedAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Gate Claim Blocked Profile',
            'slug' => 'gate-claim-blocked-profile-'.$userId,
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        $blockedAccount->setAttribute('account_profile_deletion_gate', [
            'attempt_id' => 'another-deletion-attempt',
            'attempt_generation' => 1,
        ]);
        $blockedAccount->save();

        Sanctum::actingAs($user, ['*']);
        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertStatus(409);

        $firstAccount->refresh();
        $blockedAccount->refresh();
        $firstProfile->refresh();
        $blockedProfile->refresh();

        $this->assertNotNull(AccountUser::query()->find($userId));
        $this->assertNull($firstAccount->account_profile_deletion_gate);
        $this->assertSame(
            'another-deletion-attempt',
            $blockedAccount->account_profile_deletion_gate['attempt_id'] ?? null,
        );
        $this->assertNull($firstProfile->account_profile_deletion_attempt_id);
        $this->assertNull($blockedProfile->account_profile_deletion_attempt_id);
        $this->assertSame(
            0,
            DB::connection('tenant')
                ->getDatabase()
                ->selectCollection('account_profile_deletion_attempts')
                ->countDocuments(['_id' => $userId]),
        );
    }

    public function test_expired_pre_capture_claim_resumes_into_one_atomic_capture_and_fence(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Expired Capture Claim Target',
            'phones' => ['+5527999990215'],
        ]);
        $userId = (string) $user->_id;
        $account = Account::create([
            'name' => 'Expired Capture Claim Account',
            'slug' => 'expired-capture-claim-account-'.$userId,
            'document' => ['type' => 'cpf', 'number' => 'EXPIRED-CAPTURE-CLAIM-'.$userId],
            'ownership_state' => 'unmanaged',
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Expired Capture Claim Profile',
            'slug' => 'expired-capture-claim-profile-'.$userId,
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        DB::connection('tenant')->getDatabase()->selectCollection('account_profile_deletion_attempts')->insertOne([
            '_id' => $userId,
            'schema_version' => 1,
            'attempt_generation' => 1,
            'state_revision' => 1,
            'phase' => 'capture_claimed',
            'claim_token' => 'expired-capture-claim',
            'claim_expires_at' => new \MongoDB\BSON\UTCDateTime((int) now()->subMinute()->getTimestampMs()),
            'profile_descriptors' => [],
            'account_descriptors' => [],
            'attempts' => 1,
            'last_error' => null,
            'created_at' => new \MongoDB\BSON\UTCDateTime((int) now()->subMinute()->getTimestampMs()),
            'updated_at' => new \MongoDB\BSON\UTCDateTime((int) now()->subMinute()->getTimestampMs()),
        ]);

        $attempt = app(CurrentTenantAccountDeletionAttemptService::class)->captureOrResume($userId);

        $this->assertSame('captured_and_fenced', $attempt['phase'] ?? null);
        $this->assertSame(2, (int) ($attempt['attempts'] ?? 0));
        $this->assertCount(1, $attempt['profile_descriptors'] ?? []);
        $this->assertCount(1, $attempt['account_descriptors'] ?? []);
        $profile->refresh();
        $account->refresh();
        $this->assertSame($userId, $profile->account_profile_deletion_attempt_id);
        $this->assertSame(1, (int) $profile->lifecycle_fence_revision);
        $this->assertSame($userId, $account->account_profile_deletion_gate['attempt_id'] ?? null);
    }

    public function test_active_pre_capture_claim_blocks_personal_profile_update_before_account_gates_are_installed(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Active Capture Claim Target',
            'phones' => ['+5527999990216'],
        ]);
        $userId = (string) $user->_id;
        $account = Account::create([
            'name' => 'Active Capture Claim Account',
            'slug' => 'active-capture-claim-account-'.$userId,
            'document' => ['type' => 'cpf', 'number' => 'ACTIVE-CAPTURE-CLAIM-'.$userId],
            'ownership_state' => 'unmanaged',
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Active Capture Claim Profile',
            'slug' => 'active-capture-claim-profile-'.$userId,
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        DB::connection('tenant')->getDatabase()->selectCollection('account_profile_deletion_attempts')->insertOne([
            '_id' => $userId,
            'schema_version' => 1,
            'attempt_generation' => 1,
            'state_revision' => 1,
            'phase' => 'capture_claimed',
            'claim_token' => 'active-capture-claim',
            'claim_expires_at' => new \MongoDB\BSON\UTCDateTime((int) now()->addMinute()->getTimestampMs()),
            'profile_descriptors' => [],
            'account_descriptors' => [],
            'attempts' => 1,
            'last_error' => null,
            'created_at' => new \MongoDB\BSON\UTCDateTime((int) now()->getTimestampMs()),
            'updated_at' => new \MongoDB\BSON\UTCDateTime((int) now()->getTimestampMs()),
        ]);

        try {
            app(AccountProfileManagementService::class)->update(
                $profile,
                ['display_name' => 'Must Not Persist During Capture Claim'],
                commandId: 'u07a-active-capture-claim-update-'.$userId,
            );
            $this->fail('A personal Profile update must conflict while capture is claimed.');
        } catch (\App\Exceptions\FoundationControlPlane\ConcurrencyConflictException) {
            // The active pre-capture claim is the deletion serialization point.
        }

        $this->assertSame('Active Capture Claim Profile', (string) $profile->fresh()->display_name);
    }

    public function test_active_pre_capture_claim_blocks_personal_profile_creation_before_account_gates_are_installed(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Active Capture Claim Creation Target',
            'phones' => ['+5527999990217'],
        ]);
        $userId = (string) $user->_id;
        $existingAccount = Account::create([
            'name' => 'Active Capture Claim Existing Account',
            'slug' => 'active-capture-claim-existing-account-'.$userId,
            'document' => ['type' => 'cpf', 'number' => 'ACTIVE-CAPTURE-CLAIM-EXISTING-'.$userId],
            'ownership_state' => 'unmanaged',
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        AccountProfile::create([
            'account_id' => (string) $existingAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Active Capture Claim Existing Profile',
            'slug' => 'active-capture-claim-existing-profile-'.$userId,
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        $candidateAccount = Account::create([
            'name' => 'Active Capture Claim Candidate Account',
            'slug' => 'active-capture-claim-candidate-account-'.$userId,
            'document' => ['type' => 'cpf', 'number' => 'ACTIVE-CAPTURE-CLAIM-CANDIDATE-'.$userId],
            'ownership_state' => 'unmanaged',
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        DB::connection('tenant')->getDatabase()->selectCollection('account_profile_deletion_attempts')->insertOne([
            '_id' => $userId,
            'schema_version' => 1,
            'attempt_generation' => 1,
            'state_revision' => 1,
            'phase' => 'capture_claimed',
            'claim_token' => 'active-capture-claim-create',
            'claim_expires_at' => new \MongoDB\BSON\UTCDateTime((int) now()->addMinute()->getTimestampMs()),
            'profile_descriptors' => [],
            'account_descriptors' => [],
            'attempts' => 1,
            'last_error' => null,
            'created_at' => new \MongoDB\BSON\UTCDateTime((int) now()->getTimestampMs()),
            'updated_at' => new \MongoDB\BSON\UTCDateTime((int) now()->getTimestampMs()),
        ]);

        try {
            app(AccountProfileManagementService::class)->create([
                'account_id' => (string) $candidateAccount->_id,
                'profile_type' => 'personal',
                'display_name' => 'Must Not Create During Capture Claim',
                'slug' => 'active-capture-claim-created-profile-'.$userId,
                'created_by' => $userId,
                'created_by_type' => 'tenant',
            ], 'u07a-active-capture-claim-create-'.$userId);
            $this->fail('A personal Profile creation must conflict while capture is claimed.');
        } catch (\App\Exceptions\FoundationControlPlane\ConcurrencyConflictException) {
            // The active pre-capture claim is the deletion serialization point.
        }

        $this->assertFalse(
            AccountProfile::query()
                ->where('account_id', (string) $candidateAccount->_id)
                ->where('profile_type', 'personal')
                ->exists(),
        );
    }

    public function test_captured_personal_account_deletion_blocks_new_personal_profile_creation(): void
    {
        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Captured Creation Target',
            'phones' => ['+5527999990218'],
        ]);
        $userId = (string) $user->_id;
        $existingAccount = Account::create([
            'name' => 'Captured Creation Existing Account',
            'slug' => 'captured-creation-existing-account-'.$userId,
            'document' => ['type' => 'cpf', 'number' => 'CAPTURED-CREATION-EXISTING-'.$userId],
            'ownership_state' => 'unmanaged',
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        AccountProfile::create([
            'account_id' => (string) $existingAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Captured Creation Existing Profile',
            'slug' => 'captured-creation-existing-profile-'.$userId,
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);
        $candidateAccount = Account::create([
            'name' => 'Captured Creation Candidate Account',
            'slug' => 'captured-creation-candidate-account-'.$userId,
            'document' => ['type' => 'cpf', 'number' => 'CAPTURED-CREATION-CANDIDATE-'.$userId],
            'ownership_state' => 'unmanaged',
            'created_by' => $userId,
            'created_by_type' => 'tenant',
        ]);

        $attempt = app(CurrentTenantAccountDeletionAttemptService::class)->captureOrResume($userId);
        $this->assertSame('captured_and_fenced', $attempt['phase'] ?? null);

        try {
            app(AccountProfileManagementService::class)->create([
                'account_id' => (string) $candidateAccount->_id,
                'profile_type' => 'personal',
                'display_name' => 'Must Not Create After Capture',
                'slug' => 'captured-creation-created-profile-'.$userId,
                'created_by' => $userId,
                'created_by_type' => 'tenant',
            ], 'u07a-captured-creation-'.$userId);
            $this->fail('A personal Profile creation must conflict after capture.');
        } catch (\App\Exceptions\FoundationControlPlane\ConcurrencyConflictException) {
            // The active deletion attempt owns future personal Profile creation.
        }

        $this->assertFalse(
            AccountProfile::query()
                ->where('account_id', (string) $candidateAccount->_id)
                ->where('profile_type', 'personal')
                ->exists(),
        );
    }

    public function test_personal_account_discovery_reads_are_constant_for_twelve_and_many_candidates(): void
    {
        $twelveCandidateReads = $this->capturePersonalAccountDiscoveryReads(12);
        $manyCandidateReads = $this->capturePersonalAccountDiscoveryReads(36);

        $this->assertSame(
            $twelveCandidateReads,
            $manyCandidateReads,
            'Personal-account discovery reads must remain cardinality-invariant. '
                .'The complete mutation-operation budget belongs to the separate PCV Mongo command-monitoring artifact.',
        );
    }

    /**
     * The Laravel connection query log proves the ORM discovery shape only. It
     * intentionally does not claim a complete deletion-operation budget:
     * CurrentTenantAccountDeletionService also uses direct Mongo collection
     * operations, which this log does not reliably observe.
     *
     * @todo PCV-EPS-E2 must capture Mongo command monitoring for the complete
     * deletion mutation path and enforce its own bounded-operation contract.
     *
     * @return array{initial_profile_discovery: int, owned_account_discovery: int, live_profile_graph_discovery: int, membership_discovery: int}
     */
    private function capturePersonalAccountDiscoveryReads(int $candidateCount): array
    {
        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => "Bounded Candidate Target {$candidateCount}",
            'phones' => [sprintf('+55279999%04d', $candidateCount)],
        ]);
        $targetId = (string) $target->_id;

        foreach (range(1, $candidateCount) as $index) {
            $account = Account::create([
                'name' => "Bounded Personal Account {$index}",
                'slug' => "bounded-{$candidateCount}-personal-account-{$index}",
                'document' => ['type' => 'cpf', 'number' => "BOUNDED-{$candidateCount}-{$index}-{$targetId}"],
                'ownership_state' => 'unmanaged',
                'created_by' => $targetId,
                'created_by_type' => 'tenant',
            ]);
            AccountProfile::create([
                'account_id' => (string) $account->_id,
                'profile_type' => 'personal',
                'display_name' => "Bounded Personal {$index}",
                'slug' => "bounded-{$candidateCount}-personal-{$index}",
                'created_by' => $targetId,
                'created_by_type' => 'tenant',
            ]);
        }

        $connection = DB::connection('tenant');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        Sanctum::actingAs($target, ['*']);
        $this->deleteJson("{$this->base_api_tenant}profile", [
            'confirmation' => 'remove_account',
        ])->assertNoContent();

        $queries = collect($connection->getQueryLog());
        $queryLogJson = json_encode($queries->all(), JSON_UNESCAPED_SLASHES);
        $initialProfileDiscoveryReads = $queries->filter(static function (array $query): bool {
            $serialized = json_encode($query, JSON_UNESCAPED_SLASHES);

            return str_contains($serialized, '\"find\" : \"account_profiles\"')
                && str_contains($serialized, 'created_by_type')
                && str_contains($serialized, 'profile_type');
        });
        $accountOwnershipReads = $queries->filter(static function (array $query): bool {
            $serialized = json_encode($query, JSON_UNESCAPED_SLASHES);

            return str_contains($serialized, '\"find\" : \"accounts\"')
                && str_contains($serialized, 'created_by_type');
        });
        $profileGraphReads = $queries->filter(static function (array $query): bool {
            $serialized = json_encode($query, JSON_UNESCAPED_SLASHES);

            return str_contains($serialized, '\"find\" : \"account_profiles\"')
                && str_contains($serialized, '\"account_id\" : { \"$in\"');
        });
        $membershipReads = $queries->filter(static fn (array $query): bool => str_contains(
            json_encode($query, JSON_UNESCAPED_SLASHES),
            'account_roles.account_id',
        ));

        // Candidate Profiles and eligible Accounts are read once before their
        // transactionally adjacent revalidation. The profile graph and account
        // memberships need only that later bulk revalidation. Every class is
        // cardinality-invariant regardless of candidate count.
        $this->assertCount(2, $initialProfileDiscoveryReads, "Personal-profile discovery must use preflight + transactional revalidation bulk reads: {$queryLogJson}");
        $this->assertCount(2, $accountOwnershipReads, "Account ownership must use preflight + transactional revalidation bulk reads: {$queryLogJson}");
        $this->assertCount(1, $profileGraphReads, "Profile graph must use one transactionally revalidated bulk read: {$queryLogJson}");
        $this->assertCount(1, $membershipReads, "Memberships must use one transactionally revalidated bulk read: {$queryLogJson}");
        $this->assertNull(AccountUser::withTrashed()->find($targetId));

        return [
            'initial_profile_discovery' => $initialProfileDiscoveryReads->count(),
            'owned_account_discovery' => $accountOwnershipReads->count(),
            'live_profile_graph_discovery' => $profileGraphReads->count(),
            'membership_discovery' => $membershipReads->count(),
        ];
    }
}
