<?php

declare(strict_types=1);

namespace Tests\Feature\Invites;

use App\Application\AccountProfiles\AccountProfileBootstrapService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\Invites\Models\Tenants\InviteCommandIdempotency;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteFeedProjection;
use Belluga\Invites\Models\Tenants\InviteQuotaCounter;
use Belluga\Invites\Models\Tenants\InviteShareCode;
use Belluga\Invites\Models\Tenants\PrincipalSocialMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class StoreReleaseSocialGraphTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        InviteEdge::query()->delete();
        InviteFeedProjection::query()->delete();
        InviteQuotaCounter::query()->delete();
        InviteCommandIdempotency::query()->delete();
        InviteShareCode::query()->delete();
        ContactHashDirectory::query()->delete();
        PrincipalSocialMetric::query()->delete();
        FavoriteEdge::query()->delete();
        Event::query()->delete();
        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('contact_groups')
            ->deleteMany([]);

        $this->makePersonalProfilesInviteable();
    }

    public function test_contact_import_returns_profile_scoped_inviteable_match_and_respects_discoverability(): void
    {
        $viewer = $this->createReleaseUser('Viewer User', '+55 27 99999-0001');
        $target = $this->createReleaseUser('Target User', '+55 27 99999-0002');
        $targetProfile = $this->personalProfileFor($target);
        $targetProfile->visibility = 'friends_only';
        $targetProfile->discoverable_by_contacts = true;
        $targetProfile->save();

        Sanctum::actingAs($viewer, ['*']);

        $phoneHash = hash('sha256', '5527999990002');
        $response = $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'phone', 'hash' => $phoneHash],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('matches.0.receiver_account_profile_id', (string) $targetProfile->_id);
        $response->assertJsonPath('matches.0.inviteable_reasons.0', 'contact_match');
        $response->assertJsonPath('matches.0.profile_exposure_level', 'capped_profile');
        $response->assertJsonPath('matches.0.is_inviteable', true);

        $targetProfile->discoverable_by_contacts = false;
        $targetProfile->save();

        $blocked = $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'phone', 'hash' => $phoneHash],
            ],
        ]);

        $blocked->assertOk();
        $this->assertSame([], $blocked->json('matches'));
    }

    public function test_inviteable_contacts_merge_contact_match_favorites_and_friend_reasons_without_duplicates(): void
    {
        $viewer = $this->createReleaseUser('Viewer Person', '+55 27 99999-1001');
        $target = $this->createReleaseUser('Friend Person', '+55 27 99999-1002');
        $viewerProfile = $this->personalProfileFor($viewer);
        $targetProfile = $this->personalProfileFor($target);

        Sanctum::actingAs($viewer, ['*']);
        $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'phone', 'hash' => hash('sha256', '5527999991002')],
            ],
        ])->assertOk();

        $this->favorite($viewer, $targetProfile);
        $this->favorite($target, $viewerProfile);

        $response = $this->getJson("{$this->base_api_tenant}contacts/inviteables");

        $response->assertOk();
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.receiver_account_profile_id', (string) $targetProfile->_id);
        $this->assertEqualsCanonicalizing(
            ['contact_match', 'favorite_by_you', 'favorited_you', 'friend'],
            $response->json('items.0.inviteable_reasons'),
        );
        $response->assertJsonPath('items.0.profile_exposure_level', 'full_profile');
    }

    public function test_contact_groups_dedupe_members_and_prune_recipients_that_cease_to_be_inviteable(): void
    {
        $viewer = $this->createReleaseUser('Group Owner', '+55 27 99999-2001');
        $target = $this->createReleaseUser('Grouped Friend', '+55 27 99999-2002');
        $targetProfile = $this->personalProfileFor($target);

        Sanctum::actingAs($viewer, ['*']);
        $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'phone', 'hash' => hash('sha256', '5527999992002')],
            ],
        ])->assertOk();

        $create = $this->postJson("{$this->base_api_tenant}contact-groups", [
            'name' => 'Rolê',
            'recipient_account_profile_ids' => [
                (string) $targetProfile->_id,
                (string) $targetProfile->_id,
            ],
        ]);

        $create->assertCreated();
        $create->assertJsonPath('data.name', 'Rolê');
        $create->assertJsonCount(1, 'data.recipient_account_profile_ids');

        $targetProfile->discoverable_by_contacts = false;
        $targetProfile->save();

        $groups = $this->getJson("{$this->base_api_tenant}contact-groups");

        $groups->assertOk();
        $groups->assertJsonPath('data.0.name', 'Rolê');
        $this->assertSame([], $groups->json('data.0.recipient_account_profile_ids'));
    }

    public function test_contact_group_crud_is_owner_private_and_validated(): void
    {
        $owner = $this->createReleaseUser('Private Group Owner', '+55 27 99999-2101');
        $otherOwner = $this->createReleaseUser('Other Group Owner', '+55 27 99999-2102');
        $target = $this->createReleaseUser('Private Group Friend', '+55 27 99999-2103');
        $secondTarget = $this->createReleaseUser('Private Group Second', '+55 27 99999-2104');
        $targetProfile = $this->personalProfileFor($target);
        $secondTargetProfile = $this->personalProfileFor($secondTarget);

        Sanctum::actingAs($owner, ['*']);
        foreach ([$target, $secondTarget] as $matchedUser) {
            $this->postJson("{$this->base_api_tenant}contacts/import", [
                'contacts' => [
                    ['type' => 'phone', 'hash' => hash('sha256', preg_replace('/\D+/', '', (string) $matchedUser->phones[0]))],
                ],
            ])->assertOk();
        }

        $this->postJson("{$this->base_api_tenant}contact-groups", [
            'name' => '',
            'recipient_account_profile_ids' => [],
        ])->assertUnprocessable();

        $oversizedMembers = array_fill(0, 201, (string) $targetProfile->_id);
        $this->postJson("{$this->base_api_tenant}contact-groups", [
            'name' => 'Too Big',
            'recipient_account_profile_ids' => $oversizedMembers,
        ])->assertUnprocessable();

        $create = $this->postJson("{$this->base_api_tenant}contact-groups", [
            'name' => 'Privado',
            'recipient_account_profile_ids' => [(string) $targetProfile->_id],
        ]);
        $create->assertCreated();
        $groupId = (string) $create->json('data.id');

        $rename = $this->patchJson("{$this->base_api_tenant}contact-groups/{$groupId}", [
            'name' => 'Privado editado',
            'recipient_account_profile_ids' => [
                (string) $targetProfile->_id,
                (string) $secondTargetProfile->_id,
            ],
        ]);

        $rename->assertOk();
        $rename->assertJsonPath('data.name', 'Privado editado');
        $this->assertEqualsCanonicalizing(
            [(string) $targetProfile->_id, (string) $secondTargetProfile->_id],
            $rename->json('data.recipient_account_profile_ids'),
        );

        $this->deleteJson("{$this->base_api_tenant}contact-groups/{$groupId}")->assertNoContent();
        $this->getJson("{$this->base_api_tenant}contact-groups")->assertOk()->assertJsonCount(0, 'data');

        $privateGroup = $this->postJson("{$this->base_api_tenant}contact-groups", [
            'name' => 'Privado para outro usuário',
            'recipient_account_profile_ids' => [(string) $targetProfile->_id],
        ]);
        $privateGroup->assertCreated();
        $privateGroupId = (string) $privateGroup->json('data.id');

        Sanctum::actingAs($otherOwner, ['*']);
        $this->patchJson("{$this->base_api_tenant}contact-groups/{$privateGroupId}", [
            'name' => 'Invasão',
        ])->assertNotFound();
        $this->deleteJson("{$this->base_api_tenant}contact-groups/{$privateGroupId}")->assertNotFound();
        $this->assertTrue(
            \App\Models\Tenants\ContactGroup::query()
                ->where('owner_user_id', (string) $owner->getKey())
                ->whereKey($privateGroupId)
                ->exists(),
        );
    }

    public function test_inviteable_reason_and_privacy_negative_cases_are_deterministic(): void
    {
        $viewer = $this->createReleaseUser('Reason Viewer', '+55 27 99999-2201');
        $favoriteOnly = $this->createReleaseUser('Reason Favorite', '+55 27 99999-2202');
        $favoritedViewer = $this->createReleaseUser('Reason Approver', '+55 27 99999-2203');
        $friend = $this->createReleaseUser('Reason Friend', '+55 27 99999-2204');

        $viewerProfile = $this->personalProfileFor($viewer);
        $favoriteOnlyProfile = $this->personalProfileFor($favoriteOnly);
        $favoritedViewerProfile = $this->personalProfileFor($favoritedViewer);
        $friendProfile = $this->personalProfileFor($friend);

        foreach ([$favoriteOnlyProfile, $favoritedViewerProfile, $friendProfile] as $profile) {
            $profile->visibility = 'friends_only';
            $profile->save();
        }

        $this->favorite($viewer, $favoriteOnlyProfile);
        $this->favorite($favoritedViewer, $viewerProfile);
        $this->favorite($viewer, $friendProfile);
        $this->favorite($friend, $viewerProfile);

        Sanctum::actingAs($viewer, ['*']);
        $response = $this->getJson("{$this->base_api_tenant}contacts/inviteables");
        $response->assertOk();
        $items = collect($response->json('items'))->keyBy('receiver_account_profile_id');

        $favoriteOnlyItem = $items[(string) $favoriteOnlyProfile->_id];
        $this->assertEqualsCanonicalizing(['favorite_by_you'], $favoriteOnlyItem['inviteable_reasons']);
        $this->assertSame('capped_profile', $favoriteOnlyItem['profile_exposure_level']);

        $favoritedViewerItem = $items[(string) $favoritedViewerProfile->_id];
        $this->assertEqualsCanonicalizing(['favorited_you'], $favoritedViewerItem['inviteable_reasons']);
        $this->assertSame('full_profile', $favoritedViewerItem['profile_exposure_level']);

        $friendItem = $items[(string) $friendProfile->_id];
        $this->assertEqualsCanonicalizing(['favorite_by_you', 'favorited_you', 'friend'], $friendItem['inviteable_reasons']);
        $this->assertSame('full_profile', $friendItem['profile_exposure_level']);
    }

    public function test_contact_import_suppresses_non_inviteable_profiles_and_user_matches_without_profile(): void
    {
        $viewer = $this->createReleaseUser('Legacy Match Viewer', '+55 27 99999-2301');
        $nonInviteable = $this->createReleaseUser('Legacy Non Inviteable', '+55 27 99999-2302');
        $nonInviteableProfile = $this->personalProfileFor($nonInviteable);
        $legacyUser = AccountUser::query()->create([
            'identity_state' => 'registered',
            'name' => 'Legacy No Profile',
            'emails' => ['legacy-no-profile-'.Str::random(6).'@example.org'],
            'phones' => ['+55 27 99999-2303'],
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
        ]);

        TenantProfileType::query()
            ->where('type', 'personal')
            ->update([
                'capabilities.is_inviteable' => false,
            ]);

        Sanctum::actingAs($viewer, ['*']);
        $blocked = $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'phone', 'hash' => hash('sha256', '5527999992302')],
            ],
        ]);
        $blocked->assertOk();
        $this->assertSame([], $blocked->json('matches'));

        $this->makePersonalProfilesInviteable();

        $legacy = $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'phone', 'hash' => hash('sha256', '5527999992303')],
            ],
        ]);
        $legacy->assertOk();
        $this->assertSame([], $legacy->json('matches'));

        $this->assertNotSame('', (string) $nonInviteableProfile->_id);
        $this->assertNotSame('', (string) $legacyUser->_id);
    }

    public function test_hot_mutation_payloads_have_server_side_size_caps(): void
    {
        $sender = $this->createReleaseUser('Payload Cap Sender', '+55 27 99999-2401');
        $receiver = $this->createReleaseUser('Payload Cap Receiver', '+55 27 99999-2402');
        $receiverProfile = $this->personalProfileFor($receiver);
        $event = $this->createEvent();

        Sanctum::actingAs($sender, ['*']);

        $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => array_fill(0, 501, [
                'type' => 'phone',
                'hash' => hash('sha256', '5527999992402'),
            ]),
        ])->assertUnprocessable();

        $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($event),
            'recipients' => array_fill(0, 101, [
                'receiver_account_profile_id' => (string) $receiverProfile->_id,
            ]),
        ])->assertUnprocessable();
    }

    public function test_direct_invite_can_target_account_profile_recipient_identity(): void
    {
        $sender = $this->createReleaseUser('Sender Profile', '+55 27 99999-3001');
        $receiver = $this->createReleaseUser('Receiver Profile', '+55 27 99999-3002');
        $receiverProfile = $this->personalProfileFor($receiver);
        $event = $this->createEvent();

        Sanctum::actingAs($sender, ['*']);

        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($event),
            'recipients' => [
                ['receiver_account_profile_id' => (string) $receiverProfile->_id],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('created.0.receiver_account_profile_id', (string) $receiverProfile->_id);

        $edge = InviteEdge::query()->where('receiver_account_profile_id', (string) $receiverProfile->_id)->first();
        $this->assertNotNull($edge);
        $this->assertSame((string) $receiver->_id, (string) $edge->receiver_user_id);
    }

    public function test_account_profile_recipient_acceptance_supersedes_competing_invites_by_profile_identity(): void
    {
        $firstSender = $this->createReleaseUser('First Profile Sender', '+55 27 99999-3101');
        $secondSender = $this->createReleaseUser('Second Profile Sender', '+55 27 99999-3102');
        $receiver = $this->createReleaseUser('Profile Acceptance Receiver', '+55 27 99999-3103');
        $unrelated = $this->createReleaseUser('Profile Acceptance Unrelated', '+55 27 99999-3104');
        $receiverProfile = $this->personalProfileFor($receiver);
        $event = $this->createEvent();

        Sanctum::actingAs($firstSender, ['*']);
        $firstInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($event),
            'recipients' => [
                ['receiver_account_profile_id' => (string) $receiverProfile->_id],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondSender, ['*']);
        $secondInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($event),
            'recipients' => [
                ['receiver_account_profile_id' => (string) $receiverProfile->_id],
            ],
        ])->json('created.0.invite_id');

        $competingInvite = InviteEdge::query()->find($secondInviteId);
        $this->assertNotNull($competingInvite);
        $competingInvite->receiver_user_id = (string) $unrelated->_id;
        $competingInvite->save();

        Sanctum::actingAs($receiver, ['*']);
        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/{$firstInviteId}/accept", []);

        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('status', 'accepted');
        $this->assertContains($secondInviteId, $acceptResponse->json('superseded_invite_ids'));

        $supersededInvite = InviteEdge::query()->find($secondInviteId);
        $this->assertNotNull($supersededInvite);
        $this->assertSame('superseded', (string) $supersededInvite->status);
        $this->assertSame('other_invite_credited', (string) $supersededInvite->supersession_reason);
        $this->assertFalse((bool) $supersededInvite->credited_acceptance);
    }

    public function test_account_profile_recipient_rejects_legacy_receiver_user_actor_mismatch(): void
    {
        $sender = $this->createReleaseUser('Profile Auth Sender', '+55 27 99999-3151');
        $receiver = $this->createReleaseUser('Profile Auth Receiver', '+55 27 99999-3152');
        $legacyActor = AccountUser::query()->create([
            'identity_state' => 'registered',
            'name' => 'Legacy Receiver Actor',
            'emails' => ['legacy-receiver-actor-'.Str::random(6).'@example.org'],
            'phones' => ['+55 27 99999-3153'],
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
        ]);
        $receiverProfile = $this->personalProfileFor($receiver);
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);

        Sanctum::actingAs($sender, ['*']);
        $inviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => [
                'event_id' => (string) $event->_id,
                'occurrence_id' => $occurrenceId,
            ],
            'recipients' => [
                ['receiver_account_profile_id' => (string) $receiverProfile->_id],
            ],
        ])->json('created.0.invite_id');

        $invite = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($invite);
        $invite->receiver_user_id = (string) $legacyActor->_id;
        $invite->save();

        TenantProfileType::query()
            ->where('type', 'personal')
            ->update([
                'capabilities.is_inviteable' => false,
            ]);

        Sanctum::actingAs($legacyActor, ['*']);
        $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/accept", [])->assertNotFound();
        $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/decline", [])->assertNotFound();

        $stillPending = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($stillPending);
        $this->assertSame('pending', (string) $stillPending->status);
        $this->assertFalse((bool) $stillPending->credited_acceptance);

        Sanctum::actingAs($receiver, ['*']);
        $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/accept", [])
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('credited_acceptance', true);
    }

    public function test_account_profile_recipient_direct_confirmation_supersedes_by_profile_identity(): void
    {
        $sender = $this->createReleaseUser('Direct Confirmation Sender', '+55 27 99999-3201');
        $receiver = $this->createReleaseUser('Direct Confirmation Receiver', '+55 27 99999-3202');
        $unrelated = $this->createReleaseUser('Direct Confirmation Unrelated', '+55 27 99999-3203');
        $receiverProfile = $this->personalProfileFor($receiver);
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);

        Sanctum::actingAs($sender, ['*']);
        $inviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => [
                'event_id' => (string) $event->_id,
                'occurrence_id' => $occurrenceId,
            ],
            'recipients' => [
                ['receiver_account_profile_id' => (string) $receiverProfile->_id],
            ],
        ])->json('created.0.invite_id');

        $invite = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($invite);
        $invite->receiver_user_id = (string) $unrelated->_id;
        $invite->save();

        Sanctum::actingAs($receiver, ['*']);
        $this->postJson("{$this->base_api_tenant}events/{$event->_id}/attendance/confirm", [
            'occurrence_id' => $occurrenceId,
        ])
            ->assertOk();

        $supersededInvite = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($supersededInvite);
        $this->assertSame('superseded', (string) $supersededInvite->status);
        $this->assertSame('direct_confirmation', (string) $supersededInvite->supersession_reason);
        $this->assertFalse((bool) $supersededInvite->credited_acceptance);
    }

    public function test_share_materialization_uses_account_profile_recipient_identity(): void
    {
        $creditedSender = $this->createReleaseUser('Credited Share Sender', '+55 27 99999-3301');
        $shareSender = $this->createReleaseUser('Profile Share Sender', '+55 27 99999-3302');
        $receiver = $this->createReleaseUser('Profile Share Receiver', '+55 27 99999-3303');
        $unrelated = $this->createReleaseUser('Profile Share Unrelated', '+55 27 99999-3304');
        $receiverProfile = $this->personalProfileFor($receiver);
        $event = $this->createEvent();

        Sanctum::actingAs($creditedSender, ['*']);
        $creditedInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($event),
            'recipients' => [
                ['receiver_account_profile_id' => (string) $receiverProfile->_id],
            ],
        ])->json('created.0.invite_id');

        $creditedInvite = InviteEdge::query()->find($creditedInviteId);
        $this->assertNotNull($creditedInvite);
        $creditedInvite->receiver_user_id = (string) $unrelated->_id;
        $creditedInvite->save();

        Sanctum::actingAs($receiver, ['*']);
        $this->postJson("{$this->base_api_tenant}invites/{$creditedInviteId}/accept", [])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        Sanctum::actingAs($shareSender, ['*']);
        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');

        TenantProfileType::query()
            ->where('type', 'personal')
            ->update([
                'capabilities.is_inviteable' => false,
            ]);

        Sanctum::actingAs($receiver, ['*']);
        $materializeResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", []);
        $materializeResponse->assertOk();
        $materializeResponse->assertJsonPath('status', 'superseded');
        $materializeResponse->assertJsonPath('credited_acceptance', false);

        $materializedInviteId = (string) $materializeResponse->json('invite_id');
        $materializedInvite = InviteEdge::query()->find($materializedInviteId);
        $this->assertNotNull($materializedInvite);
        $this->assertSame((string) $receiverProfile->_id, (string) $materializedInvite->receiver_account_profile_id);
        $this->assertSame('other_invite_credited', (string) $materializedInvite->supersession_reason);

        $materializedInvite->receiver_user_id = (string) $unrelated->_id;
        $materializedInvite->save();

        $replayResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", []);
        $replayResponse->assertOk();
        $replayResponse->assertJsonPath('invite_id', $materializedInviteId);

        $this->assertSame(
            1,
            InviteEdge::query()
                ->where('receiver_account_profile_id', (string) $receiverProfile->_id)
                ->where('source', 'share_url')
                ->count(),
        );
    }

    public function test_legacy_user_recipient_is_rejected_and_contact_hash_respects_profile_inviteability(): void
    {
        $sender = $this->createReleaseUser('Legacy Inviteability Sender', '+55 27 99999-3401');
        $receiver = $this->createReleaseUser('Legacy Inviteability Receiver', '+55 27 99999-3402');
        $legacyUser = AccountUser::query()->create([
            'identity_state' => 'registered',
            'name' => 'Legacy Inviteability No Profile',
            'emails' => ['legacy-inviteability-'.Str::random(6).'@example.org'],
            'phones' => ['+55 27 99999-3403'],
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
        ]);
        $event = $this->createEvent();
        $contactHash = hash('sha256', '5527999993402');

        ContactHashDirectory::query()->create([
            'importing_user_id' => (string) $sender->_id,
            'contact_hash' => $contactHash,
            'matched_user_id' => (string) $receiver->_id,
            'type' => 'phone',
            'salt_version' => 'v1',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        TenantProfileType::query()
            ->where('type', 'personal')
            ->update([
                'capabilities.is_inviteable' => false,
            ]);

        Sanctum::actingAs($sender, ['*']);

        $directResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($event),
            'recipients' => [
                ['receiver_user_id' => (string) $receiver->_id],
            ],
        ]);
        $directResponse->assertUnprocessable();

        $hashResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($event),
            'recipients' => [
                ['contact_hash' => $contactHash],
            ],
        ]);
        $hashResponse->assertOk();
        $hashResponse->assertJsonCount(0, 'created');
        $hashResponse->assertJsonPath('blocked.0.reason', 'suppressed');

        $legacyFallbackResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($event),
            'recipients' => [
                ['receiver_user_id' => (string) $legacyUser->_id],
            ],
        ]);
        $legacyFallbackResponse->assertUnprocessable();
    }

    private function createReleaseUser(string $name, string $phone): AccountUser
    {
        $user = AccountUser::query()->create([
            'identity_state' => 'registered',
            'name' => $name,
            'emails' => [Str::slug($name).'-'.Str::random(6).'@example.org'],
            'phones' => [$phone],
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
        ]);

        app(AccountProfileBootstrapService::class)->ensurePersonalAccount($user);

        return $user->fresh();
    }

    private function personalProfileFor(AccountUser $user): AccountProfile
    {
        return AccountProfile::query()
            ->where('created_by', (string) $user->_id)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->firstOrFail();
    }

    private function favorite(AccountUser $owner, AccountProfile $targetProfile): void
    {
        FavoriteEdge::query()->updateOrCreate(
            [
                'owner_user_id' => (string) $owner->_id,
                'registry_key' => 'account_profile',
                'target_type' => 'account_profile',
                'target_id' => (string) $targetProfile->_id,
            ],
            [
                'favorited_at' => Carbon::now(),
            ],
        );
    }

    private function makePersonalProfilesInviteable(): void
    {
        TenantProfileType::query()
            ->where('type', 'personal')
            ->update([
                'capabilities' => [
                    'is_favoritable' => true,
                    'is_inviteable' => true,
                    'is_poi_enabled' => false,
                    'is_reference_location_enabled' => false,
                    'has_bio' => false,
                    'has_content' => false,
                    'has_taxonomies' => false,
                    'has_avatar' => false,
                    'has_cover' => false,
                    'has_events' => false,
                ],
            ]);
    }

    private function createEvent(): Event
    {
        $now = Carbon::now();

        $event = Event::query()->create([
            'title' => 'Invite Event',
            'slug' => 'invite-event-'.Str::random(6),
            'content' => 'Invite event content',
            'location' => [
                'mode' => 'physical',
                'label' => 'Invite Venue',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0, -20.0],
                ],
            ],
            'place_ref' => [
                'type' => 'venue',
                'id' => 'venue-1',
                'metadata' => ['display_name' => 'Invite Venue'],
            ],
            'type' => [
                'id' => 'show',
                'name' => 'Show',
                'slug' => 'show',
            ],
            'venue' => [
                'id' => 'venue-1',
                'display_name' => 'Invite Venue',
                'hero_image_url' => 'https://example.org/hero.jpg',
            ],
            'thumb' => [
                'url' => 'https://example.org/thumb.jpg',
            ],
            'date_time_start' => $now->copy()->addDay(),
            'date_time_end' => $now->copy()->addDay()->addHours(4),
            'tags' => ['music', 'night'],
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subMinute(),
            ],
            'is_active' => true,
        ]);

        app(EventOccurrenceSyncService::class)->syncFromEvent($event, [[
            'date_time_start' => Carbon::instance($event->date_time_start),
            'date_time_end' => $event->date_time_end ? Carbon::instance($event->date_time_end) : null,
        ]]);

        return $event->fresh();
    }

    private function firstOccurrenceId(Event $event): string
    {
        $occurrence = EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('occurrence_index')
            ->firstOrFail();

        return (string) $occurrence->_id;
    }

    /**
     * @return array{event_id:string,occurrence_id:string}
     */
    private function targetRef(Event $event): array
    {
        return [
            'event_id' => (string) $event->_id,
            'occurrence_id' => $this->firstOccurrenceId($event),
        ];
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);

        $tenant = Tenant::query()->first();
        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? '');
        }
    }
}
