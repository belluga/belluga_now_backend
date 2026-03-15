<?php

declare(strict_types=1);

namespace Tests\Feature\Invites;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\Invites\Models\Tenants\InviteCommandIdempotency;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteFeedProjection;
use Belluga\Invites\Models\Tenants\InviteQuotaCounter;
use Belluga\Invites\Models\Tenants\InviteShareCode;
use Belluga\Invites\Models\Tenants\PrincipalSocialMetric;
use Illuminate\Support\Carbon;
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
    }

    public function test_send_invite_creates_grouped_feed_and_updates_metrics(): void
    {
        Sanctum::actingAs($this->sender, ['*']);

        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => [
                'event_id' => (string) $this->event->_id,
            ],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
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
        $this->assertNotNull($projection);
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
        $feedResponse->assertJsonPath('invites.0.message', 'Come with us');
    }

    public function test_send_invite_to_multiple_recipients_updates_created_count_and_metrics(): void
    {
        $secondReceiver = $this->createAccountUser('Second Receiver User');

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => [
                'event_id' => (string) $this->event->_id,
            ],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
                ['receiver_user_id' => (string) $secondReceiver->_id],
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
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondInviter, ['*']);
        $secondInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($this->receiver, ['*']);
        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/{$firstInviteId}/accept", []);
        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('status', 'accepted');
        $acceptResponse->assertJsonPath('credited_acceptance', true);
        $acceptResponse->assertJsonPath('closed_duplicate_invite_ids.0', $secondInviteId);

        $firstEdge = InviteEdge::query()->find($firstInviteId);
        $secondEdge = InviteEdge::query()->find($secondInviteId);
        $this->assertSame('accepted', (string) $firstEdge?->status);
        $this->assertTrue((bool) $firstEdge?->credited_acceptance);
        $this->assertSame('closed_duplicate', (string) $secondEdge?->status);

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
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondInviter, ['*']);
        $secondInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
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
        $secondResponse->assertJsonPath('closed_duplicate_invite_ids.0', $secondInviteId);

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
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
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

    public function test_accept_invite_rejects_idempotency_key_reused_for_another_invite(): void
    {
        $anotherEvent = $this->createEvent();
        Sanctum::actingAs($this->sender, ['*']);

        $firstInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ])->json('created.0.invite_id');

        $secondInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $anotherEvent->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
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
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondInviter, ['*']);
        $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
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

        $contactHash = hash('sha256', strtolower(trim((string) $this->receiver->emails[0])));

        $importResponse = $this->postJson("{$this->base_api_tenant}contacts/import", [
            'contacts' => [
                ['type' => 'email', 'hash' => $contactHash],
            ],
        ]);
        $importResponse->assertOk();
        $importResponse->assertJsonPath('matches.0.user_id', (string) $this->receiver->_id);

        $sendResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['contact_hash' => $contactHash],
            ],
        ]);
        $sendResponse->assertOk();
        $sendResponse->assertJsonPath('created.0.receiver_user_id', (string) $this->receiver->_id);
    }

    public function test_share_accept_rejects_anonymous_user(): void
    {
        Sanctum::actingAs($this->sender, ['*']);

        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
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
            ->where('source', 'share_url')
            ->first();
        $this->assertNull($edge);
    }

    public function test_share_accept_works_for_authenticated_user(): void
    {
        Sanctum::actingAs($this->sender, ['*']);

        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');
        $this->assertNotSame('', $code);

        $authenticated = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($authenticated, []);
        $acceptResponse = $this->postJson("{$this->base_api_tenant}invites/share/{$code}/accept", []);
        $acceptResponse->assertOk();
        $acceptResponse->assertJsonPath('status', 'accepted');
        $acceptResponse->assertJsonPath('attribution_bound', true);
        $acceptResponse->assertJsonPath('inviter_principal.kind', 'user');

        $edge = InviteEdge::query()
            ->where('receiver_user_id', (string) $authenticated->_id)
            ->where('source', 'share_url')
            ->first();
        $this->assertNotNull($edge);
        $this->assertSame('accepted', (string) $edge->status);
        $this->assertTrue((bool) $edge->credited_acceptance);
    }

    public function test_share_accept_replays_by_idempotency_key(): void
    {
        Sanctum::actingAs($this->sender, ['*']);
        $shareResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
        ]);
        $shareResponse->assertOk();
        $code = (string) $shareResponse->json('code');

        $authenticated = $this->createVerifiedIdentityUser();
        Sanctum::actingAs($authenticated, []);

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
    }

    public function test_send_invite_requires_authentication(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_send_invite_validates_recipients_payload(): void
    {
        Sanctum::actingAs($this->sender, ['*']);

        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                [],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['recipients.0']);
    }

    public function test_sender_quota_rejection_returns_structured_429_payload(): void
    {
        config()->set('invites.limits.max_invites_per_day_per_user_actor', 1);

        $secondReceiver = $this->createAccountUser('Quota Receiver');
        Sanctum::actingAs($this->sender, ['*']);

        $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ])->assertOk();

        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $secondReceiver->_id],
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
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ]);
        $firstResponse->assertOk();
        $firstResponse->assertJsonCount(1, 'created');
        $firstResponse->assertJsonCount(0, 'blocked');

        $secondResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $secondEvent->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
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
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ])->assertOk();

        $duplicateResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $this->receiver->_id],
            ],
        ]);
        $duplicateResponse->assertOk();
        $duplicateResponse->assertJsonPath('already_invited.0.receiver_user_id', (string) $this->receiver->_id);

        $quotaResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
            'recipients' => [
                ['receiver_user_id' => (string) $secondReceiver->_id],
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
            'target_ref' => ['event_id' => (string) $this->event->_id],
        ])->assertOk();

        $response = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => ['event_id' => (string) $secondEvent->_id],
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
            'target_ref' => ['event_id' => (string) $this->event->_id],
        ]);
        $firstResponse->assertOk();
        $code = (string) $firstResponse->json('code');

        InviteShareCode::query()
            ->where('code', $code)
            ->update(['expires_at' => Carbon::now()->subSecond()]);

        $response = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
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
            'target_ref' => ['event_id' => (string) $this->event->_id],
        ]);
        $firstResponse->assertOk();
        $firstCode = (string) $firstResponse->json('code');

        InviteShareCode::query()
            ->where('code', $firstCode)
            ->update(['expires_at' => Carbon::now()->subSecond()]);

        $cooldownResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => ['event_id' => (string) $this->event->_id],
        ]);
        $cooldownResponse->assertStatus(429);
        $cooldownResponse->assertJsonPath('code', 'share_rate_limited');

        $secondTargetResponse = $this->postJson("{$this->base_api_tenant}invites/share", [
            'target_ref' => ['event_id' => (string) $secondEvent->_id],
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
