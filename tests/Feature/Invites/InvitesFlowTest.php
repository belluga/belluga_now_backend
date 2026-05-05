<?php

declare(strict_types=1);

namespace Tests\Feature\Invites;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Jobs\Telemetry\DeliverTelemetryEventJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantSettings;
use App\Models\Tenants\TenantProfileType;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\Invites\Models\Tenants\InviteCommandIdempotency;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteFeedProjection;
use Belluga\Invites\Models\Tenants\InviteQuotaCounter;
use Belluga\Invites\Models\Tenants\InviteShareCode;
use Belluga\Invites\Models\Tenants\PrincipalSocialMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class InvitesFlowTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountUserService $userService;

    private AccountUser $sender;

    private AccountUser $receiver;

    private Event $event;

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
        Event::query()->delete();

        [$this->account] = $this->seedAccountWithRole(['*']);
        $this->userService = $this->app->make(AccountUserService::class);
        $this->sender = $this->createAccountUser('Sender User');
        $this->receiver = $this->createAccountUser('Receiver User');
        $this->event = $this->createEvent();
        $this->makePersonalProfilesInviteable();
    }

    public function test_send_invite_creates_grouped_feed_and_updates_metrics(): void
    {
        Sanctum::actingAs($this->sender, ['*']);

        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
            'message' => 'Come with us',
        ]);

        $response->assertOk();
        $inviteId = (string) $response->json('created.0.invite_id');
        $this->assertNotSame('', $inviteId);

        $edge = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($edge);
        $this->assertSame('pending', (string) $edge->status);
        $this->assertSame('direct_invite', (string) $edge->source);

        $projection = InviteFeedProjection::query()
            ->where('receiver_user_id', (string) $this->receiver->_id)
            ->first();
        $occurrence = EventOccurrence::query()
            ->where('_id', $this->firstOccurrenceId($this->event))
            ->firstOrFail();
        $this->assertNotNull($projection);
        $this->assertSame($this->firstOccurrenceId($this->event), (string) $projection->occurrence_id);
        $this->assertSame($occurrence->starts_at->toISOString(), $projection->event_date?->toISOString());
        $this->assertCount(1, (array) $projection->inviter_candidates);

        $metric = PrincipalSocialMetric::query()
            ->where('principal_kind', 'user')
            ->where('principal_id', (string) $this->sender->_id)
            ->first();
        $this->assertNotNull($metric);
        $this->assertSame(1, (int) $metric->invites_sent);

        Sanctum::actingAs($this->receiver, ['*']);
        $feedResponse = $this->getJson("{$this->base_api_tenant}invites");
        $feedResponse->assertOk();
        $feedResponse->assertJsonPath('invites.0.inviter_candidates.0.invite_id', $inviteId);
        $feedResponse->assertJsonPath('invites.0.target_ref.occurrence_id', $this->firstOccurrenceId($this->event));
        $feedResponse->assertJsonPath('invites.0.event_date', $occurrence->starts_at->toISOString());
        $feedResponse->assertJsonPath('invites.0.location', 'Invite Venue');
        $feedResponse->assertJsonPath('invites.0.message', 'Come with us');
    }

    public function test_send_invite_to_multiple_recipients_updates_created_count_and_metrics(): void
    {
        $secondReceiver = $this->createAccountUser('Second Receiver User');

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
                ['receiver_account_profile_id' => $this->accountProfileIdFor($secondReceiver)],
            ],
            'message' => 'Join this event',
        ]);

        $response->assertOk();
        $response->assertJsonCount(2, 'created');
        $response->assertJsonCount(0, 'already_invited');
        $response->assertJsonCount(0, 'blocked');

        $metric = PrincipalSocialMetric::query()
            ->where('principal_kind', 'user')
            ->where('principal_id', (string) $this->sender->_id)
            ->first();
        $this->assertNotNull($metric);
        $this->assertSame(2, (int) $metric->invites_sent);

        $this->assertSame(
            2,
            InviteEdge::query()
                ->where('issued_by_user_id', (string) $this->sender->_id)
                ->where('event_id', (string) $this->event->_id)
                ->count(),
        );
    }

    public function test_accepting_one_invite_closes_duplicate_candidates(): void
    {
        $secondInviter = $this->createAccountUser('Second Inviter');

        Sanctum::actingAs($this->sender, ['*']);
        $firstInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondInviter, ['*']);
        $secondInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($this->receiver, ['*']);
        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/{$firstInviteId}/accept", []);
        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('status', 'accepted');
        $acceptResponse->assertJsonPath('credited_acceptance', true);
        $acceptResponse->assertJsonPath('superseded_invite_ids.0', $secondInviteId);

        $firstEdge = InviteEdge::query()->find($firstInviteId);
        $secondEdge = InviteEdge::query()->find($secondInviteId);
        $this->assertSame('accepted', (string) $firstEdge?->status);
        $this->assertTrue((bool) $firstEdge?->credited_acceptance);
        $this->assertSame('superseded', (string) $secondEdge?->status);
        $this->assertSame('other_invite_credited', (string) $secondEdge?->supersession_reason);

        $metric = PrincipalSocialMetric::query()
            ->where('principal_kind', 'user')
            ->where('principal_id', (string) $this->sender->_id)
            ->first();
        $this->assertNotNull($metric);
        $this->assertSame(1, (int) $metric->credited_invite_acceptances);

        $feedResponse = $this->getJson("{$this->base_api_tenant}invites");
        $feedResponse->assertOk();
        $this->assertSame([], $feedResponse->json('invites'));
    }

    public function test_accept_invite_replays_by_idempotency_key_without_double_side_effects(): void
    {
        $secondInviter = $this->createAccountUser('Second Replay Inviter');

        Sanctum::actingAs($this->sender, ['*']);
        $firstInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondInviter, ['*']);
        $secondInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($this->receiver, ['*']);
        $firstResponse = $this->postJson("{$this->base_api_tenant}invites/{$firstInviteId}/accept", [
            'idempotency_key' => 'invite-accept-replay-001',
        ]);
        $firstResponse->assertOk();
        $firstResponse->assertJsonPath('status', 'accepted');

        $secondResponse = $this->postJson("{$this->base_api_tenant}invites/{$firstInviteId}/accept", [
            'idempotency_key' => 'invite-accept-replay-001',
        ]);
        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('status', 'accepted');
        $secondResponse->assertJsonPath('invite_id', $firstResponse->json('invite_id'));
        $secondResponse->assertJsonPath('superseded_invite_ids.0', $secondInviteId);

        $metric = PrincipalSocialMetric::query()
            ->where('principal_kind', 'user')
            ->where('principal_id', (string) $this->sender->_id)
            ->first();
        $this->assertNotNull($metric);
        $this->assertSame(1, (int) $metric->credited_invite_acceptances);
    }

    public function test_accepting_already_accepted_invite_does_not_increment_metrics_twice(): void
    {
        Sanctum::actingAs($this->sender, ['*']);
        $inviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($this->receiver, ['*']);
        $firstResponse = $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/accept", []);
        $firstResponse->assertOk();
        $firstResponse->assertJsonPath('status', 'accepted');

        $secondResponse = $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/accept", []);
        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('status', 'already_accepted');

        $metric = PrincipalSocialMetric::query()
            ->where('principal_kind', 'user')
            ->where('principal_id', (string) $this->sender->_id)
            ->first();
        $this->assertNotNull($metric);
        $this->assertSame(1, (int) $metric->credited_invite_acceptances);
    }

    public function test_direct_confirmation_superseded_invite_cannot_late_bind_attribution(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);

        Sanctum::actingAs($this->sender, ['*']);
        $inviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => [
                'event_id' => (string) $this->event->_id,
                'occurrence_id' => $occurrenceId,
            ],
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($this->receiver, ['*']);
        $this->postJson("{$this->base_api_tenant}events/{$this->event->_id}/attendance/confirm", [
            'occurrence_id' => $occurrenceId,
        ])
            ->assertOk();

        $inviteAfterConfirmation = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($inviteAfterConfirmation);
        $this->assertSame('superseded', (string) $inviteAfterConfirmation->status);
        $this->assertSame('direct_confirmation', (string) $inviteAfterConfirmation->supersession_reason);
        $this->assertFalse((bool) $inviteAfterConfirmation->credited_acceptance);

        $feedResponse = $this->getJson("{$this->base_api_tenant}invites");
        $feedResponse->assertOk();
        $this->assertSame([], $feedResponse->json('invites'));

        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/accept", []);
        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('status', 'already_accepted');
        $acceptResponse->assertJsonPath('credited_acceptance', false);

        $inviteAfterLateAccept = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($inviteAfterLateAccept);
        $this->assertSame('superseded', (string) $inviteAfterLateAccept->status);
        $this->assertSame('direct_confirmation', (string) $inviteAfterLateAccept->supersession_reason);
        $this->assertFalse((bool) $inviteAfterLateAccept->credited_acceptance);
    }

    public function test_direct_invite_sent_after_receiver_confirmation_is_created_superseded_and_hidden_from_feed(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $receiverAccountProfileId = $this->accountProfileIdFor($this->receiver);

        Sanctum::actingAs($this->receiver, ['*']);
        $this->postJson("{$this->base_api_tenant}events/{$this->event->_id}/attendance/confirm", [
            'occurrence_id' => $occurrenceId,
        ])
            ->assertOk();

        Sanctum::actingAs($this->sender, ['*']);
        $sendResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => [
                'event_id' => (string) $this->event->_id,
                'occurrence_id' => $occurrenceId,
            ],
            'recipients' => [
                ['receiver_account_profile_id' => $receiverAccountProfileId],
            ],
        ]);

        $sendResponse->assertOk();
        $sendResponse->assertJsonPath('created.0.receiver_account_profile_id', $receiverAccountProfileId);
        $sendResponse->assertJsonPath('created.0.status', 'superseded');
        $inviteId = (string) $sendResponse->json('created.0.invite_id');
        $this->assertNotSame('', $inviteId);

        $edge = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($edge);
        $this->assertSame('superseded', (string) $edge->status);
        $this->assertSame('direct_confirmation', (string) $edge->supersession_reason);
        $this->assertFalse((bool) $edge->credited_acceptance);

        $this->assertFalse(
            InviteFeedProjection::query()
                ->where('receiver_user_id', (string) $this->receiver->_id)
                ->where('group_key', (string) $this->event->_id.'::'.$occurrenceId)
                ->exists(),
        );

        Sanctum::actingAs($this->receiver, ['*']);
        $feedResponse = $this->getJson("{$this->base_api_tenant}invites");
        $feedResponse->assertOk();
        $this->assertSame([], $feedResponse->json('invites'));
    }

    public function test_accept_invite_rejects_idempotency_key_reused_for_another_invite(): void
    {
        $anotherEvent = $this->createEvent();
        Sanctum::actingAs($this->sender, ['*']);

        $firstInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        $secondInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($anotherEvent),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($this->receiver, ['*']);
        $this->postJson("{$this->base_api_tenant}invites/{$firstInviteId}/accept", [
            'idempotency_key' => 'invite-accept-conflict-001',
        ])->assertOk();

        $response = $this->postJson("{$this->base_api_tenant}invites/{$secondInviteId}/accept", [
            'idempotency_key' => 'invite-accept-conflict-001',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('status', 'rejected');
        $response->assertJsonPath('code', 'idempotency_key_reused_with_different_payload');
    }

    public function test_declining_one_candidate_keeps_other_pending_inviter_visible(): void
    {
        $secondInviter = $this->createAccountUser('Second Decline Inviter');

        Sanctum::actingAs($this->sender, ['*']);
        $firstInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondInviter, ['*']);
        $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->assertOk();

        Sanctum::actingAs($this->receiver, ['*']);
        $declineResponse = $this->postJson("{$this->base_api_tenant}invites/{$firstInviteId}/decline", []);
        $declineResponse->assertOk();
        $declineResponse->assertJsonPath('status', 'declined');
        $declineResponse->assertJsonPath('group_has_other_pending', true);

        $feedResponse = $this->getJson("{$this->base_api_tenant}invites");
        $feedResponse->assertOk();
        $feedResponse->assertJsonCount(1, 'invites');
        $feedResponse->assertJsonCount(1, 'invites.0.inviter_candidates');
    }

    public function test_contacts_import_matches_user_and_direct_invite_can_target_contact_hash(): void
    {
        Sanctum::actingAs($this->sender, ['*']);
        $this->accountProfileIdFor($this->receiver);

        $contactHash = hash('sha256', strtolower(trim((string) $this->receiver->emails[0])));

        $importResponse = $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'email', 'hash' => $contactHash],
            ],
        ]);
        $importResponse->assertOk();
        $importResponse->assertJsonPath('matches.0.user_id', (string) $this->receiver->_id);

        $sendResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['contact_hash' => $contactHash],
            ],
        ]);
        $sendResponse->assertOk();
        $sendResponse->assertJsonPath('created.0.receiver_account_profile_id', $this->accountProfileIdFor($this->receiver));
    }

    public function test_account_user_materializes_contact_hashes_and_import_matches_email_and_phone(): void
    {
        Sanctum::actingAs($this->sender, ['*']);
        $this->accountProfileIdFor($this->receiver);

        $phone = '+55 (27) 99999-1234';
        $this->receiver->phones = [$phone];
        $this->receiver->save();
        $this->receiver->refresh();

        $expectedEmailHash = hash('sha256', strtolower(trim((string) $this->receiver->emails[0])));
        $expectedPhoneHash = hash('sha256', '5527999991234');

        $this->assertContains($expectedEmailHash, (array) ($this->receiver->email_hashes ?? []));
        $this->assertContains($expectedPhoneHash, (array) ($this->receiver->phone_hashes ?? []));

        $importResponse = $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'email', 'hash' => $expectedEmailHash],
                ['type' => 'phone', 'hash' => $expectedPhoneHash],
            ],
        ]);

        $importResponse->assertOk();
        $matches = collect($importResponse->json('matches'));
        $this->assertCount(2, $matches);
        $this->assertTrue($matches->every(fn (array $match): bool => ($match['user_id'] ?? null) === (string) $this->receiver->_id));
        $this->assertEqualsCanonicalizing(
            [$expectedEmailHash, $expectedPhoneHash],
            $matches->pluck('contact_hash')->all(),
        );
    }

    public function test_contacts_import_accepts_max_batch_and_reimport_upserts_directory_rows(): void
    {
        Sanctum::actingAs($this->sender, ['*']);
        $this->accountProfileIdFor($this->receiver);

        $matchedHash = hash('sha256', strtolower(trim((string) $this->receiver->emails[0])));
        $contacts = [
            ['type' => 'email', 'hash' => $matchedHash],
        ];
        for ($index = 1; $index < 500; $index++) {
            $contacts[] = [
                'type' => 'email',
                'hash' => hash('sha256', 'unmatched-'.$index.'@example.org'),
            ];
        }

        $importResponse = $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => $contacts,
        ]);

        $importResponse->assertOk();
        $importResponse->assertJsonPath('matches.0.user_id', (string) $this->receiver->_id);
        $this->assertSame(500, ContactHashDirectory::query()->count());

        $reimportResponse = $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'email', 'hash' => $matchedHash],
            ],
        ]);

        $reimportResponse->assertOk();
        $this->assertSame(500, ContactHashDirectory::query()->count());
        $directoryRow = ContactHashDirectory::query()
            ->where('importing_user_id', (string) $this->sender->_id)
            ->where('contact_hash', $matchedHash)
            ->first();
        $this->assertNotNull($directoryRow);
        $this->assertSame((string) $this->receiver->_id, (string) $directoryRow->matched_user_id);
    }

    public function test_share_materialize_rejects_anonymous_user(): void
    {
        Sanctum::actingAs($this->sender, ['*']);

        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');
        $this->assertNotSame('', $code);

        $anonymous = AccountUser::query()->create([
            'identity_state' => 'anonymous',
            'emails' => [],
            'phones' => [],
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
        ]);
        Sanctum::actingAs($anonymous, []);

        $materializeResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", []);

        $materializeResponse->assertStatus(401);
        $materializeResponse->assertJsonPath('status', 'rejected');
        $materializeResponse->assertJsonPath('code', 'auth_required');

        $edge = InviteEdge::query()
            ->where('receiver_user_id', (string) $anonymous->_id)
            ->where('source', 'share_url')
            ->first();
        $this->assertNull($edge);
    }

    public function test_share_accept_by_code_rejects_anonymous_user(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        Sanctum::actingAs($this->sender, ['*']);

        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');
        $this->assertNotSame('', $code);

        $anonymous = AccountUser::query()->create([
            'identity_state' => 'anonymous',
            'emails' => [],
            'phones' => [],
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
        ]);
        Sanctum::actingAs($anonymous, []);

        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/accept", []);
        $acceptResponse->assertStatus(401);
        $acceptResponse->assertJsonPath('status', 'rejected');
        $acceptResponse->assertJsonPath('code', 'auth_required');

        $edge = InviteEdge::query()
            ->where('receiver_user_id', (string) $anonymous->_id)
            ->where('occurrence_id', $occurrenceId)
            ->where('source', 'share_url')
            ->first();
        $this->assertNull($edge);

        $metric = PrincipalSocialMetric::query()
            ->where('principal_kind', 'user')
            ->where('principal_id', (string) $this->sender->_id)
            ->first();
        $this->assertTrue(
            $metric === null || (int) $metric->credited_invite_acceptances === 0,
            'Anonymous share accept rejection must not credit inviter metrics.',
        );
    }

    public function test_share_accept_replays_by_idempotency_key_without_creating_duplicate_edges(): void
    {
        Sanctum::actingAs($this->sender, ['*']);

        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');
        $this->assertNotSame('', $code);

        $receiver = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($receiver, []);

        $firstResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/accept", [
            'idempotency_key' => 'share-accept-replay-001',
        ]);
        $firstResponse->assertOk();
        $firstResponse->assertJsonPath('status', 'accepted');

        $secondResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/accept", [
            'idempotency_key' => 'share-accept-replay-001',
        ]);
        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('status', 'accepted');
        $secondResponse->assertJsonPath('invite_id', $firstResponse->json('invite_id'));
        $receiverAccountProfileId = $this->accountProfileIdFor($receiver);

        $this->assertSame(
            1,
            InviteEdge::query()
                ->where('receiver_account_profile_id', $receiverAccountProfileId)
                ->where('event_id', (string) $this->event->_id)
                ->where('occurrence_id', $this->firstOccurrenceId($this->event))
                ->where('source', 'share_url')
                ->count(),
        );
    }

    public function test_share_accept_emits_invite_accepted_with_funnel_join_keys(): void
    {
        Queue::fake();
        $this->configureInviteTelemetry();

        Sanctum::actingAs($this->sender, ['*']);
        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');
        $this->assertNotSame('', $code);

        $receiver = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($receiver, []);

        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/accept", []);
        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('status', 'accepted');

        Queue::assertPushed(
            DeliverTelemetryEventJob::class,
            function (DeliverTelemetryEventJob $job) use ($code): bool {
                $envelope = $this->telemetryEnvelope($job);
                $metadata = $envelope['metadata'] ?? [];

                return ($envelope['event'] ?? null) === 'invite.accepted'
                    && ($metadata['code'] ?? null) === $code
                    && ($metadata['source'] ?? null) === 'invite_flow'
                    && ($metadata['invite_source'] ?? null) === 'share_url'
                    && ($metadata['event_id'] ?? null) === (string) $this->event->_id
                    && ($metadata['occurrence_id'] ?? null) === $this->firstOccurrenceId($this->event)
                    && ($metadata['status'] ?? null) === 'accepted'
                    && ($metadata['credited_acceptance'] ?? null) === true;
            }
        );
    }

    public function test_share_preview_resolves_without_authentication(): void
    {
        $code = 'PREVIEW1234';
        $occurrenceId = $this->firstOccurrenceId($this->event);
        InviteShareCode::query()->create([
            'code' => $code,
            'event_id' => (string) $this->event->_id,
            'occurrence_id' => $occurrenceId,
            'inviter_principal' => [
                'kind' => 'user',
                'principal_id' => (string) $this->sender->_id,
            ],
            'issued_by_user_id' => (string) $this->sender->_id,
            'account_profile_id' => null,
            'inviter_display_name' => 'Sender User',
            'inviter_avatar_url' => 'https://example.com/sender.png',
            'expires_at' => Carbon::now()->addDay(),
        ]);

        $response = $this->getJson("{$this->base_api_tenant}invites/share/{$code}");

        $response->assertOk();
        $response->assertJsonPath('code', $code);
        $response->assertJsonPath('inviter_principal.kind', 'user');
        $response->assertJsonPath('invite.target_ref.event_id', (string) $this->event->_id);
        $response->assertJsonPath('invite.target_ref.occurrence_id', $occurrenceId);
        $response->assertJsonPath('invite.inviter_candidates.0.display_name', 'Sender User');
        $response->assertJsonPath('invite.inviter_candidates.0.status', 'pending');
    }

    public function test_share_preview_rejects_unknown_or_expired_code(): void
    {
        $missingResponse = $this->getJson("{$this->base_api_tenant}invites/share/MISSING1234");
        $missingResponse->assertStatus(404);
        $missingResponse->assertJsonPath('status', 'rejected');
        $missingResponse->assertJsonPath('code', 'invite_share_not_found');

        $occurrenceId = $this->firstOccurrenceId($this->event);
        InviteShareCode::query()->create([
            'code' => 'EXPIRED123',
            'event_id' => (string) $this->event->_id,
            'occurrence_id' => $occurrenceId,
            'inviter_principal' => [
                'kind' => 'user',
                'principal_id' => (string) $this->sender->_id,
            ],
            'issued_by_user_id' => (string) $this->sender->_id,
            'account_profile_id' => null,
            'inviter_display_name' => 'Sender User',
            'inviter_avatar_url' => null,
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $expiredResponse = $this->getJson("{$this->base_api_tenant}invites/share/EXPIRED123");
        $expiredResponse->assertStatus(404);
        $expiredResponse->assertJsonPath('status', 'rejected');
        $expiredResponse->assertJsonPath('code', 'invite_share_not_found');
    }

    public function test_share_materialize_creates_pending_invite_for_authenticated_user(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        Sanctum::actingAs($this->sender, ['*']);

        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');
        $this->assertNotSame('', $code);

        $authenticated = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($authenticated, []);
        $materializeResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", []);
        $materializeResponse->assertOk();
        $materializeResponse->assertJsonPath('status', 'pending');
        $materializeResponse->assertJsonPath('credited_acceptance', false);
        $materializeResponse->assertJsonPath('inviter_principal.kind', 'user');
        $materializeResponse->assertJsonPath('target_ref.occurrence_id', $occurrenceId);
        $receiverAccountProfileId = $this->accountProfileIdFor($authenticated);

        $edge = InviteEdge::query()
            ->where('receiver_account_profile_id', $receiverAccountProfileId)
            ->where('source', 'share_url')
            ->first();
        $this->assertNotNull($edge);
        $this->assertSame($occurrenceId, (string) $edge->occurrence_id);
        $this->assertSame('pending', (string) $edge->status);
        $this->assertFalse((bool) $edge->credited_acceptance);
    }

    public function test_share_materialize_reuses_existing_invite_edge_for_same_user_inviter_and_target(): void
    {
        Sanctum::actingAs($this->sender, ['*']);

        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');
        $this->assertNotSame('', $code);

        $authenticated = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($authenticated, []);

        $firstResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", []);
        $firstResponse->assertOk();
        $firstResponse->assertJsonPath('status', 'pending');
        $firstResponse->assertJsonPath('target_ref.occurrence_id', $this->firstOccurrenceId($this->event));

        $secondResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", []);
        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('status', 'pending');
        $secondResponse->assertJsonPath('invite_id', $firstResponse->json('invite_id'));
        $receiverAccountProfileId = $this->accountProfileIdFor($authenticated);

        $edges = InviteEdge::query()
            ->where('receiver_account_profile_id', $receiverAccountProfileId)
            ->where('event_id', (string) $this->event->_id)
            ->where('occurrence_id', $this->firstOccurrenceId($this->event))
            ->where('source', 'share_url')
            ->get();

        $this->assertCount(1, $edges);
    }

    public function test_share_materialize_then_standard_accept_is_canonical(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        Sanctum::actingAs($this->sender, ['*']);
        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');

        $authenticated = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($authenticated, []);
        $materializeResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", []);
        $materializeResponse->assertOk();
        $materializeResponse->assertJsonPath('status', 'pending');
        $materializeResponse->assertJsonPath('target_ref.occurrence_id', $occurrenceId);

        $inviteId = (string) $materializeResponse->json('invite_id');
        $this->assertNotSame('', $inviteId);

        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/accept", []);
        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('status', 'accepted');
        $acceptResponse->assertJsonPath('credited_acceptance', true);
        $acceptResponse->assertJsonPath('target_ref.occurrence_id', $occurrenceId);

        $edge = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($edge);
        $this->assertSame($occurrenceId, (string) $edge->occurrence_id);
        $this->assertSame($this->accountProfileIdFor($authenticated), (string) $edge->receiver_account_profile_id);
        $this->assertSame('accepted', (string) $edge->status);
        $this->assertTrue((bool) $edge->credited_acceptance);
    }

    public function test_share_materialize_then_standard_decline_is_canonical(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        Sanctum::actingAs($this->sender, ['*']);
        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');

        $authenticated = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($authenticated, []);
        $materializeResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", []);
        $materializeResponse->assertOk();
        $materializeResponse->assertJsonPath('status', 'pending');
        $materializeResponse->assertJsonPath('target_ref.occurrence_id', $occurrenceId);

        $inviteId = (string) $materializeResponse->json('invite_id');
        $this->assertNotSame('', $inviteId);

        $declineResponse = $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/decline", []);
        $declineResponse->assertOk();
        $declineResponse->assertJsonPath('status', 'declined');

        $edge = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($edge);
        $this->assertSame($occurrenceId, (string) $edge->occurrence_id);
        $this->assertSame($this->accountProfileIdFor($authenticated), (string) $edge->receiver_account_profile_id);
        $this->assertSame('declined', (string) $edge->status);
        $this->assertFalse((bool) $edge->credited_acceptance);
    }

    public function test_share_materialize_replays_by_idempotency_key(): void
    {
        Sanctum::actingAs($this->sender, ['*']);
        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');

        $authenticated = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($authenticated, []);

        $firstResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", [
            'idempotency_key' => 'share-materialize-replay-001',
        ]);
        $firstResponse->assertOk();
        $firstResponse->assertJsonPath('status', 'pending');

        $secondResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", [
            'idempotency_key' => 'share-materialize-replay-001',
        ]);
        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('status', 'pending');
        $secondResponse->assertJsonPath('invite_id', $firstResponse->json('invite_id'));
    }

    public function test_share_materialize_after_direct_confirmation_stays_superseded_and_cannot_late_bind_credit(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);

        Sanctum::actingAs($this->sender, ['*']);
        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => [
                'event_id' => (string) $this->event->_id,
                'occurrence_id' => $occurrenceId,
            ],
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');

        $authenticated = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($authenticated, []);
        $this->postJson("{$this->base_api_tenant}events/{$this->event->_id}/attendance/confirm", [
            'occurrence_id' => $occurrenceId,
        ])
            ->assertOk();

        $materializeResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/materialize", []);
        $materializeResponse->assertOk();
        $materializeResponse->assertJsonPath('status', 'superseded');
        $materializeResponse->assertJsonPath('credited_acceptance', false);
        $materializeResponse->assertJsonPath('target_ref.occurrence_id', $occurrenceId);

        $inviteId = (string) $materializeResponse->json('invite_id');
        $this->assertNotSame('', $inviteId);

        $inviteEdge = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($inviteEdge);
        $this->assertSame($occurrenceId, (string) $inviteEdge->occurrence_id);
        $this->assertSame($this->accountProfileIdFor($authenticated), (string) $inviteEdge->receiver_account_profile_id);
        $this->assertSame('superseded', (string) $inviteEdge->status);
        $this->assertSame('direct_confirmation', (string) $inviteEdge->supersession_reason);
        $this->assertFalse((bool) $inviteEdge->credited_acceptance);

        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/accept", []);
        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('status', 'already_accepted');
        $acceptResponse->assertJsonPath('credited_acceptance', false);
    }

    public function test_send_invite_requires_authentication(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_send_invite_validates_recipients_payload(): void
    {
        Sanctum::actingAs($this->sender, ['*']);

        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                [],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['recipients.0']);
    }

    public function test_invite_writes_require_occurrence_identity(): void
    {
        Sanctum::actingAs($this->sender, ['*']);
        $targetWithoutOccurrence = [
            'event_id' => (string) $this->event->_id,
        ];

        $directResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $targetWithoutOccurrence,
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ]);

        $directResponse->assertUnprocessable();
        $directResponse->assertJsonValidationErrors(['target_ref.occurrence_id']);

        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $targetWithoutOccurrence,
        ]);

        $shareResponse->assertUnprocessable();
        $shareResponse->assertJsonValidationErrors(['target_ref.occurrence_id']);
    }

    public function test_duplicate_invite_prevention_is_scoped_to_occurrence(): void
    {
        $event = $this->createEventWithOccurrences();
        [$firstOccurrenceId, $secondOccurrenceId] = $this->occurrenceIds($event);
        $receiverAccountProfileId = $this->accountProfileIdFor($this->receiver);

        Sanctum::actingAs($this->sender, ['*']);

        $firstResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($event, $firstOccurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $receiverAccountProfileId],
            ],
        ]);
        $firstResponse->assertOk();
        $firstResponse->assertJsonCount(1, 'created');

        $secondResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($event, $secondOccurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $receiverAccountProfileId],
            ],
        ]);
        $secondResponse->assertOk();
        $secondResponse->assertJsonCount(1, 'created');

        $duplicateResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($event, $firstOccurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $receiverAccountProfileId],
            ],
        ]);
        $duplicateResponse->assertOk();
        $duplicateResponse->assertJsonCount(0, 'created');
        $duplicateResponse->assertJsonPath('already_invited.0.receiver_account_profile_id', $receiverAccountProfileId);

        $this->assertSame(
            2,
            InviteEdge::query()
                ->where('event_id', (string) $event->_id)
                ->where('receiver_account_profile_id', $receiverAccountProfileId)
                ->count(),
        );
        $this->assertEqualsCanonicalizing(
            [$firstOccurrenceId, $secondOccurrenceId],
            InviteEdge::query()
                ->where('event_id', (string) $event->_id)
                ->where('receiver_account_profile_id', $receiverAccountProfileId)
                ->pluck('occurrence_id')
                ->map(static fn (mixed $value): string => (string) $value)
                ->all(),
        );
    }

    public function test_accepting_invite_supersedes_only_same_occurrence_candidates(): void
    {
        $event = $this->createEventWithOccurrences();
        [$firstOccurrenceId, $secondOccurrenceId] = $this->occurrenceIds($event);
        $secondInviter = $this->createAccountUser('Second Occurrence Inviter');
        $receiverAccountProfileId = $this->accountProfileIdFor($this->receiver);

        Sanctum::actingAs($this->sender, ['*']);
        $firstOccurrenceInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($event, $firstOccurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $receiverAccountProfileId],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondInviter, ['*']);
        $competingFirstOccurrenceInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($event, $firstOccurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $receiverAccountProfileId],
            ],
        ])->json('created.0.invite_id');
        $secondOccurrenceInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($event, $secondOccurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $receiverAccountProfileId],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($this->receiver, ['*']);
        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/{$firstOccurrenceInviteId}/accept", []);
        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('status', 'accepted');
        $acceptResponse->assertJsonPath('target_ref.occurrence_id', $firstOccurrenceId);
        $acceptResponse->assertJsonPath('superseded_invite_ids.0', $competingFirstOccurrenceInviteId);

        $accepted = InviteEdge::query()->find($firstOccurrenceInviteId);
        $competingFirst = InviteEdge::query()->find($competingFirstOccurrenceInviteId);
        $secondOccurrence = InviteEdge::query()->find($secondOccurrenceInviteId);

        $this->assertSame('accepted', (string) $accepted?->status);
        $this->assertSame('superseded', (string) $competingFirst?->status);
        $this->assertSame('pending', (string) $secondOccurrence?->status);
        $this->assertSame($secondOccurrenceId, (string) $secondOccurrence?->occurrence_id);
    }

    public function test_sender_quota_rejection_returns_structured_429_payload(): void
    {
        config()->set('invites.limits.max_invites_per_day_per_user_actor', 1);

        $secondReceiver = $this->createAccountUser('Quota Receiver');
        Sanctum::actingAs($this->sender, ['*']);

        $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->assertOk();

        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($secondReceiver)],
            ],
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('status', 'rejected');
        $response->assertJsonPath('code', 'rate_limited');
        $response->assertJsonPath('payload.limit_key', 'max_invites_per_day_per_user_actor');
        $response->assertJsonPath('payload.scope', 'user_actor');
        $response->assertJsonPath('payload.max_allowed', 1);
    }

    public function test_receiver_limits_are_not_enforced_in_mvp(): void
    {
        config()->set('invites.limits.max_pending_invites_per_invitee', 1);
        config()->set('invites.limits.max_invites_to_same_invitee_per_30d', 1);

        Sanctum::actingAs($this->sender, ['*']);
        $secondEvent = $this->createEvent();

        $firstResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ]);
        $firstResponse->assertOk();
        $firstResponse->assertJsonCount(1, 'created');
        $firstResponse->assertJsonCount(0, 'blocked');

        $secondResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($secondEvent),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ]);
        $secondResponse->assertOk();
        $secondResponse->assertJsonCount(1, 'created');
        $secondResponse->assertJsonCount(0, 'blocked');
    }

    public function test_duplicate_invite_does_not_consume_daily_user_actor_quota_counter(): void
    {
        config()->set('invites.limits.max_invites_per_day_per_user_actor', 1);

        $secondReceiver = $this->createAccountUser('Second Counter Receiver');
        Sanctum::actingAs($this->sender, ['*']);

        $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->assertOk();

        $duplicateResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ]);
        $duplicateResponse->assertOk();
        $duplicateResponse->assertJsonPath('already_invited.0.receiver_account_profile_id', $this->accountProfileIdFor($this->receiver));

        $quotaResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($secondReceiver)],
            ],
        ]);

        $quotaResponse->assertStatus(429);
        $quotaResponse->assertJsonPath('code', 'rate_limited');
        $quotaResponse->assertJsonPath('payload.limit_key', 'max_invites_per_day_per_user_actor');
        $quotaResponse->assertJsonPath('payload.scope', 'user_actor');
        $quotaResponse->assertJsonPath('payload.current_count', 1);
    }

    public function test_share_daily_limit_rejection_returns_structured_429_payload(): void
    {
        config()->set('invites.limits.max_share_codes_per_day_per_user_actor', 1);
        config()->set('invites.cooldowns.share_code_cooldown_seconds', 0);

        Sanctum::actingAs($this->sender, ['*']);
        $secondEvent = $this->createEvent();

        $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ])->assertOk();

        $response = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($secondEvent),
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('status', 'rejected');
        $response->assertJsonPath('code', 'rate_limited');
        $response->assertJsonPath('payload.limit_key', 'max_share_codes_per_day_per_user_actor');
        $response->assertJsonPath('payload.scope', 'share_user_actor');
        $response->assertJsonPath('payload.max_allowed', 1);
    }

    public function test_share_target_cooldown_rejection_returns_retry_metadata(): void
    {
        config()->set('invites.cooldowns.share_code_cooldown_seconds', 3600);

        Sanctum::actingAs($this->sender, ['*']);

        $firstResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $firstResponse->assertOk();
        $code = (string) $firstResponse->json('code');

        InviteShareCode::query()
            ->where('code', $code)
            ->update(['expires_at' => Carbon::now()->subSecond()]);

        $response = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('status', 'rejected');
        $response->assertJsonPath('code', 'share_rate_limited');
        $response->assertJsonPath('payload.limit_key', 'share_code_cooldown_seconds');
        $response->assertJsonPath('payload.scope', 'share_target');
        $this->assertGreaterThan(0, (int) $response->json('payload.retry_after_seconds'));
    }

    public function test_share_cooldown_rejection_does_not_consume_daily_share_quota_counter(): void
    {
        config()->set('invites.limits.max_share_codes_per_day_per_user_actor', 2);
        config()->set('invites.cooldowns.share_code_cooldown_seconds', 3600);

        Sanctum::actingAs($this->sender, ['*']);
        $secondEvent = $this->createEvent();

        $firstResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $firstResponse->assertOk();
        $firstCode = (string) $firstResponse->json('code');

        InviteShareCode::query()
            ->where('code', $firstCode)
            ->update(['expires_at' => Carbon::now()->subSecond()]);

        $cooldownResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($this->event),
        ]);
        $cooldownResponse->assertStatus(429);
        $cooldownResponse->assertJsonPath('code', 'share_rate_limited');

        $secondTargetResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => $this->targetRef($secondEvent),
        ]);
        $secondTargetResponse->assertOk();
        $secondTargetResponse->assertJsonPath('target_ref.event_id', (string) $secondEvent->_id);
    }

    public function test_invite_settings_returns_limits_cooldowns_and_reset_metadata(): void
    {
        config()->set('invites.limits.max_invites_per_day_per_user_actor', 12);
        config()->set('invites.cooldowns.share_code_cooldown_seconds', 321);

        Sanctum::actingAs($this->sender, ['*']);

        $response = $this->getJson("{$this->base_api_tenant}invites/settings");
        $response->assertOk();
        $response->assertJsonPath('limits.max_invites_per_day_per_user_actor', 12);
        $response->assertJsonPath('cooldowns.share_code_cooldown_seconds', 321);
        $this->assertIsString($response->json('reset_at'));
    }

    private function createAccountUser(string $name): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Invite Role '.Str::random(6),
            'permissions' => ['*'],
        ]);

        return $this->userService->create($this->account, [
            'name' => $name,
            'email' => Str::slug($name).'-'.Str::random(6).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function createVerifiedIdentityUser(): AccountUser
    {
        return AccountUser::query()->create([
            'identity_state' => 'verified',
            'name' => 'Share Accept Auth '.Str::random(6),
            'emails' => [Str::random(10).'@example.org'],
            'phones' => [],
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
        ]);
    }

    private function firstOccurrenceId(Event $event): string
    {
        $occurrenceIds = $this->occurrenceIds($event);
        $this->assertNotSame([], $occurrenceIds);

        return $occurrenceIds[0];
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

    /**
     * @return array{event_id:string,occurrence_id:string}
     */
    private function targetRefForOccurrence(Event $event, string $occurrenceId): array
    {
        return [
            'event_id' => (string) $event->_id,
            'occurrence_id' => $occurrenceId,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function occurrenceIds(Event $event): array
    {
        $refs = $event->fresh()?->occurrence_refs ?? [];
        if ($refs instanceof \MongoDB\Model\BSONArray || $refs instanceof \MongoDB\Model\BSONDocument) {
            $refs = $refs->getArrayCopy();
        }

        if (is_array($refs) && $refs !== []) {
            $normalized = array_values(array_filter(array_map(function (mixed $ref): ?array {
                if ($ref instanceof \MongoDB\Model\BSONArray || $ref instanceof \MongoDB\Model\BSONDocument) {
                    $ref = $ref->getArrayCopy();
                }

                return is_array($ref) ? $ref : null;
            }, $refs)));
            usort($normalized, static fn (array $left, array $right): int => ((int) ($left['order'] ?? PHP_INT_MAX)) <=> ((int) ($right['order'] ?? PHP_INT_MAX)));

            return array_values(array_filter(array_map(static fn (array $ref): string => trim((string) ($ref['occurrence_id'] ?? '')), $normalized)));
        }

        return EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->get()
            ->map(static fn (EventOccurrence $occurrence): string => (string) $occurrence->_id)
            ->values()
            ->all();
    }

    private function accountProfileIdFor(AccountUser $user): string
    {
        $profile = AccountProfile::query()
            ->where('created_by', (string) $user->_id)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->first();

        if (! $profile instanceof AccountProfile) {
            $personalAccount = Account::query()->create([
                'name' => 'Personal '.$user->_id,
                'ownership_state' => 'unmanaged',
                'document' => [
                    'type' => 'cpf',
                    'number' => 'PERSONAL-'.(string) $user->_id,
                ],
                'created_by' => (string) $user->_id,
                'created_by_type' => 'tenant',
                'updated_by' => (string) $user->_id,
                'updated_by_type' => 'tenant',
            ]);

            $profile = AccountProfile::query()->create([
                'account_id' => (string) $personalAccount->_id,
                'profile_type' => 'personal',
                'display_name' => (string) ($user->name ?? 'Receiver'),
                'created_by' => (string) $user->_id,
                'created_by_type' => 'tenant',
                'updated_by' => (string) $user->_id,
                'updated_by_type' => 'tenant',
                'is_active' => true,
            ]);
        }

        return (string) $profile->_id;
    }

    private function makePersonalProfilesInviteable(): void
    {
        TenantProfileType::query()
            ->where('type', 'personal')
            ->update([
                'capabilities.is_inviteable' => true,
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

    private function createEventWithOccurrences(): Event
    {
        $event = $this->createEvent();
        $start = Carbon::instance($event->date_time_start);
        $end = $event->date_time_end ? Carbon::instance($event->date_time_end) : null;

        app(EventOccurrenceSyncService::class)->syncFromEvent($event, [
            [
                'date_time_start' => $start,
                'date_time_end' => $end,
            ],
            [
                'date_time_start' => $start->copy()->addDay(),
                'date_time_end' => $end?->copy()->addDay(),
            ],
        ]);

        return $event->fresh();
    }

    private function configureInviteTelemetry(): void
    {
        TenantSettings::query()->delete();
        TenantSettings::create([
            'telemetry' => [
                'trackers' => [
                    [
                        'type' => 'webhook',
                        'url' => 'https://telemetry.example/ingest',
                        'track_all' => true,
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function telemetryEnvelope(DeliverTelemetryEventJob $job): array
    {
        $property = (new \ReflectionClass($job))->getProperty('envelope');
        $property->setAccessible(true);

        /** @var array<string, mixed> $envelope */
        $envelope = $property->getValue($job);

        return $envelope;
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
