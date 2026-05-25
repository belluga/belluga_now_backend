<?php

declare(strict_types=1);

namespace Tests\Feature\Invites;

use App\Application\Accounts\AccountUserService;
use App\Application\Auth\TenantScopedAccessTokenService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Jobs\Telemetry\DeliverTelemetryEventJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use App\Models\Tenants\TenantSettings;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\Invites\Models\Tenants\InviteCommandIdempotency;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteFeedProjection;
use Belluga\Invites\Models\Tenants\InviteOutboxEvent;
use Belluga\Invites\Models\Tenants\InviteQuotaCounter;
use Belluga\Invites\Models\Tenants\InviteShareCode;
use Belluga\Invites\Models\Tenants\PrincipalSocialMetric;
use Belluga\PushHandler\Contracts\FcmClientContract;
use Belluga\PushHandler\Jobs\SendPushMessageJob;
use Belluga\PushHandler\Models\Tenants\PushCredential;
use Belluga\PushHandler\Models\Tenants\PushDeliveryLog;
use Belluga\PushHandler\Models\Tenants\PushDevice;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
        InviteOutboxEvent::query()->delete();
        InviteFeedProjection::query()->delete();
        InviteQuotaCounter::query()->delete();
        InviteCommandIdempotency::query()->delete();
        InviteShareCode::query()->delete();
        ContactHashDirectory::query()->delete();
        PrincipalSocialMetric::query()->delete();
        Event::query()->delete();
        PushMessage::query()->delete();
        PushCredential::query()->delete();
        PushDevice::query()->delete();
        TenantPushSettings::query()->delete();

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

    public function test_feed_expires_finished_invites_before_returning_projections(): void
    {
        Sanctum::actingAs($this->sender, ['*']);
        $sendResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ]);

        $sendResponse->assertOk();
        $inviteId = (string) $sendResponse->json('created.0.invite_id');
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $groupKey = (string) $this->event->_id.'::'.$occurrenceId;
        $endedAt = Carbon::now()->subHour();
        $startedAt = $endedAt->copy()->subHours(3);

        $event = $this->event->fresh();
        $event->date_time_start = $startedAt;
        $event->date_time_end = $endedAt;
        $event->save();

        $occurrence = EventOccurrence::query()
            ->where('_id', $occurrenceId)
            ->firstOrFail();
        $occurrence->starts_at = $startedAt;
        $occurrence->ends_at = $endedAt;
        $occurrence->effective_ends_at = $endedAt;
        $occurrence->save();

        InviteEdge::query()
            ->where('_id', $inviteId)
            ->update([
                'expires_at' => null,
                'event_date' => $startedAt,
            ]);
        InviteFeedProjection::query()
            ->where('receiver_user_id', (string) $this->receiver->_id)
            ->where('group_key', $groupKey)
            ->update([
                'event_date' => $startedAt,
            ]);

        Sanctum::actingAs($this->receiver, ['*']);
        $feedResponse = $this->getJson("{$this->base_api_tenant}invites");
        $feedResponse->assertOk();
        $this->assertSame([], $feedResponse->json('invites'));

        $invite = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($invite);
        $this->assertSame('expired', (string) $invite->status);

        $this->assertFalse(
            InviteFeedProjection::query()
                ->where('receiver_user_id', (string) $this->receiver->_id)
                ->where('group_key', $groupKey)
                ->exists(),
        );
    }

    public function test_feed_expires_invites_when_target_occurrence_is_missing_even_with_stored_future_expiry(): void
    {
        Sanctum::actingAs($this->sender, ['*']);
        $sendResponse = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ]);

        $sendResponse->assertOk();
        $inviteId = (string) $sendResponse->json('created.0.invite_id');
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $groupKey = (string) $this->event->_id.'::'.$occurrenceId;
        $futureStart = Carbon::now()->addDays(2);
        $futureEnd = $futureStart->copy()->addHours(3);

        $event = $this->event->fresh();
        $event->date_time_start = $futureStart;
        $event->date_time_end = $futureEnd;
        $event->save();

        EventOccurrence::query()
            ->where('_id', $occurrenceId)
            ->delete();

        InviteEdge::query()
            ->where('_id', $inviteId)
            ->update([
                'expires_at' => $futureEnd,
                'event_date' => $futureStart,
            ]);
        InviteFeedProjection::query()
            ->where('receiver_user_id', (string) $this->receiver->_id)
            ->where('group_key', $groupKey)
            ->update([
                'event_date' => $futureStart,
            ]);

        Sanctum::actingAs($this->receiver, ['*']);
        $feedResponse = $this->getJson("{$this->base_api_tenant}invites");
        $feedResponse->assertOk();
        $this->assertSame([], $feedResponse->json('invites'));

        $invite = InviteEdge::query()->find($inviteId);
        $this->assertNotNull($invite);
        $this->assertSame('expired', (string) $invite->status);

        $this->assertFalse(
            InviteFeedProjection::query()
                ->where('receiver_user_id', (string) $this->receiver->_id)
                ->where('group_key', $groupKey)
                ->exists(),
        );
    }

    public function test_send_invite_authors_and_dispatches_invite_push_when_runtime_is_ready(): void
    {
        Bus::fake();
        $this->seedPushRuntimeReady();
        $this->registerActivePushToken($this->receiver, 'receiver-push-token');

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
            'message' => 'Come with us',
        ]);

        $response->assertOk();

        $message = PushMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame('tenant', (string) $message->scope);
        $this->assertSame('invite_received', (string) $message->type);
        $this->assertSame([(string) $this->receiver->_id], $message->audience['user_ids'] ?? []);
        $this->assertSame('invite_received', data_get($message->fcm_options, 'data.event'));
        $this->assertSame('invite_received', data_get($message->fcm_options, 'data.push_type'));
        $this->assertSame('ic_notification_invite', data_get($message->fcm_options, 'android.notification.icon'));
        $this->assertSame('https://example.org/thumb.jpg', data_get($message->fcm_options, 'notification.image'));
        $this->assertSame(
            data_get($message->fcm_options, 'notification.image'),
            data_get($message->fcm_options, 'android.notification.image'),
        );
        $this->assertSame(
            data_get($message->fcm_options, 'notification.image'),
            data_get($message->fcm_options, 'data.event_image_url'),
        );
        $this->assertSame('Sender User', data_get($message->fcm_options, 'data.inviter_name'));
        $this->assertNull(data_get($message->payload_template, 'layoutType'));
        $this->assertSame(
            (string) $this->event->_id,
            data_get($message->fcm_options, 'data.event_id'),
        );
        $this->assertSame(
            $this->firstOccurrenceId($this->event),
            data_get($message->fcm_options, 'data.occurrence_id'),
        );

        Bus::assertDispatched(SendPushMessageJob::class);
    }

    public function test_send_invite_job_delivers_authored_invite_push_and_marks_message_sent(): void
    {
        Bus::fake();
        PushDeliveryLog::query()->delete();
        $this->seedPushRuntimeReady();
        $this->registerActivePushToken($this->receiver, 'receiver-push-token');

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    \Carbon\Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    return [
                        'accepted_count' => count($tokens),
                        'responses' => array_map(static fn (string $token): array => [
                            'token' => $token,
                            'status' => 'accepted',
                            'provider_message_id' => 'provider-'.$token,
                        ], $tokens),
                    ];
                }
            };
        });

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
            'message' => 'Come with us',
        ]);

        $response->assertOk();

        $message = PushMessage::query()->firstOrFail();
        $job = new SendPushMessageJob((string) $message->_id, 'tenant', null);
        $job->handle(
            $this->app->make(\Belluga\PushHandler\Services\PushDeliveryService::class),
            $this->app->make(\Belluga\PushHandler\Services\PushRecipientResolver::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushPlanPolicyContract::class),
            $this->app->make(\Belluga\PushHandler\Services\PushAudienceTopologyClassifier::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelAuthorizationContract::class),
            $this->app->make(\Belluga\PushHandler\Contracts\PushChannelTargetResolverContract::class),
        );

        $message->refresh();
        $this->assertSame('sent', (string) $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertSame(1, data_get($message->metrics, 'accepted_count'));
        $this->assertSame(1, data_get($message->metrics, 'sent_count'));

        $log = PushDeliveryLog::query()->firstOrFail();
        $this->assertSame((string) $message->_id, (string) $log->push_message_id);
        $this->assertSame('accepted', (string) $log->status);
        $this->assertSame('individual_direct', (string) $log->delivery_topology);
    }

    public function test_send_invite_queue_runtime_processes_authored_push_job_and_materializes_delivery_log(): void
    {
        PushDeliveryLog::query()->delete();
        $this->seedPushRuntimeReady();
        $this->registerActivePushToken($this->receiver, 'receiver-push-token');
        $this->useMongoQueueRuntimeForTest();

        $this->app->bind(FcmClientContract::class, static function () {
            return new class implements FcmClientContract
            {
                public function send(
                    PushMessage $message,
                    array $tokens,
                    string $messageInstanceId,
                    \Carbon\Carbon $expiresAt,
                    int $ttlMinutes
                ): array {
                    return [
                        'accepted_count' => count($tokens),
                        'responses' => array_map(static fn (string $token): array => [
                            'token' => $token,
                            'status' => 'accepted',
                            'provider_message_id' => 'provider-'.$token,
                        ], $tokens),
                    ];
                }
            };
        });

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
            'message' => 'Come with us',
        ]);

        $response->assertOk();

        $message = PushMessage::query()->firstOrFail();
        $this->assertSame('scheduled', (string) $message->status);
        $this->assertGreaterThan(0, $this->queueJobCount());

        Tenant::current()?->forgetCurrent();

        $maxIterations = 5;
        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $exitCode = Artisan::call('queue:work', [
                '--once' => true,
                '--queue' => 'default',
            ]);
            $this->assertSame(0, $exitCode);

            $this->makeCanonicalTenantCurrent($this->tenant);
            $message->refresh();

            if ((string) $message->status !== 'scheduled') {
                break;
            }

            Tenant::current()?->forgetCurrent();
        }

        $this->makeCanonicalTenantCurrent($this->tenant);
        $message->refresh();

        $this->assertSame('sent', (string) $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertSame(1, data_get($message->metrics, 'accepted_count'));
        $this->assertSame(1, data_get($message->metrics, 'sent_count'));

        $log = PushDeliveryLog::query()->firstOrFail();
        $this->assertSame((string) $message->_id, (string) $log->push_message_id);
        $this->assertSame('accepted', (string) $log->status);
        $this->assertSame('individual_direct', (string) $log->delivery_topology);
    }

    public function test_send_invite_skips_invite_push_authoring_when_runtime_prerequisites_are_missing(): void
    {
        Bus::fake();

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(0, PushMessage::query()->count());
        Bus::assertNotDispatched(SendPushMessageJob::class);
    }

    public function test_send_invite_to_multiple_recipients_authors_recipient_private_direct_push_messages(): void
    {
        Bus::fake();
        $this->seedPushRuntimeReady();
        $secondReceiver = $this->createAccountUser('Second Receiver User');
        $this->registerActivePushToken($this->receiver, 'receiver-push-token');
        $this->registerActivePushToken($secondReceiver, 'second-receiver-push-token');

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

        $messages = PushMessage::query()
            ->where('type', 'invite_received')
            ->get();

        $this->assertCount(2, $messages);
        $this->assertSame(
            collect([
                (string) $this->receiver->_id,
                (string) $secondReceiver->_id,
            ])->sort()->values()->all(),
            $messages
                ->map(static fn (PushMessage $message): string => (string) (($message->audience['user_ids'][0] ?? '')))
                ->sort()
                ->values()
                ->all(),
        );

        foreach ($messages as $message) {
            $this->assertSame('users', $message->audience['type'] ?? null);
            $this->assertCount(1, $message->audience['user_ids'] ?? []);
            $this->assertSame('invite_received', data_get($message->fcm_options, 'data.event'));
            $this->assertNotEmpty(data_get($message->fcm_options, 'data.invite_id'));
            $this->assertStringStartsWith('invite-received-', (string) $message->internal_name);
        }

        Bus::assertDispatchedTimes(SendPushMessageJob::class, 2);
    }

    public function test_accept_invite_authors_and_dispatches_invite_accepted_push_to_original_sender_when_runtime_is_ready(): void
    {
        Bus::fake();
        $this->seedPushRuntimeReady();
        $this->registerActivePushToken($this->sender, 'sender-push-token');

        Sanctum::actingAs($this->sender, ['*']);
        $inviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRef($this->event),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
            'message' => 'Come with us',
        ])->json('created.0.invite_id');

        Sanctum::actingAs($this->receiver, ['*']);
        $response = $this->postJson("{$this->base_api_tenant}invites/{$inviteId}/accept", []);
        $response->assertOk();
        $response->assertJsonPath('status', 'accepted');
        $response->assertJsonPath('credited_acceptance', true);

        $message = PushMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame('tenant', (string) $message->scope);
        $this->assertSame('invite_accepted', (string) $message->type);
        $this->assertSame([(string) $this->sender->_id], $message->audience['user_ids'] ?? []);
        $this->assertSame('invite_accepted', data_get($message->fcm_options, 'data.event'));
        $this->assertSame('invite_accepted', data_get($message->fcm_options, 'data.push_type'));
        $this->assertSame((string) $this->receiver->_id, data_get($message->fcm_options, 'data.accepted_by_user_id'));
        $this->assertSame(
            $this->accountProfileIdFor($this->receiver),
            data_get($message->fcm_options, 'data.accepted_by_account_profile_id'),
        );
        $this->assertSame('Receiver User', data_get($message->fcm_options, 'data.accepted_by_display_name'));
        $this->assertSame('ic_notification_invite', data_get($message->fcm_options, 'android.notification.icon'));
        $this->assertNotEmpty(data_get($message->fcm_options, 'notification.image'));
        $this->assertNull(data_get($message->payload_template, 'layoutType'));

        Bus::assertDispatched(SendPushMessageJob::class);
    }

    public function test_invite_stream_accepts_access_token_query_for_web_sse_clients(): void
    {
        $plainTextToken = $this->app
            ->make(TenantScopedAccessTokenService::class)
            ->issueForAccountUser($this->receiver, 'Invite stream token', ['*'])
            ->plainTextToken;
        $cursor = Carbon::now()->subMinute();

        InviteOutboxEvent::query()->create([
            'topic' => 'invites.receiver.'.$this->receiver->getKey(),
            'receiver_user_id' => (string) $this->receiver->getKey(),
            'payload' => [
                'type' => 'invite.upsert',
                'marker' => 'before-query-cursor',
            ],
            'dedupe_key' => 'stream-query-cursor-before',
            'available_at' => $cursor->copy()->subSecond(),
        ]);
        InviteOutboxEvent::query()->create([
            'topic' => 'invites.receiver.'.$this->receiver->getKey(),
            'receiver_user_id' => (string) $this->receiver->getKey(),
            'payload' => [
                'type' => 'invite.upsert',
                'marker' => 'after-query-cursor',
            ],
            'dedupe_key' => 'stream-query-cursor-after',
            'available_at' => $cursor->copy()->addSecond(),
        ]);

        $response = $this->get(
            "{$this->base_api_tenant}invites/stream?access_token={$plainTextToken}&last_event_id=".rawurlencode($cursor->toISOString()),
            [
                'Accept' => 'text/event-stream',
            ]
        );

        $response->assertOk();
        $this->assertStringStartsWith(
            'text/event-stream',
            (string) $response->headers->get('Content-Type')
        );
        $streamedContent = $response->streamedContent();
        $this->assertStringContainsString('after-query-cursor', $streamedContent);
        $this->assertStringNotContainsString('before-query-cursor', $streamedContent);
    }

    public function test_invite_stream_without_cursor_does_not_replay_historical_outbox_events(): void
    {
        $plainTextToken = $this->app
            ->make(TenantScopedAccessTokenService::class)
            ->issueForAccountUser($this->receiver, 'Invite stream token', ['*'])
            ->plainTextToken;

        InviteOutboxEvent::query()->create([
            'topic' => 'invites.receiver.'.$this->receiver->getKey(),
            'receiver_user_id' => (string) $this->receiver->getKey(),
            'payload' => [
                'type' => 'invite.upsert',
                'marker' => 'historical-backlog-event',
            ],
            'dedupe_key' => 'historical-backlog-event',
            'available_at' => Carbon::now()->subMinute(),
        ]);

        $response = $this->get(
            "{$this->base_api_tenant}invites/stream?access_token={$plainTextToken}",
            [
                'Accept' => 'text/event-stream',
            ]
        );

        $response->assertOk();
        $this->assertStringStartsWith(
            'text/event-stream',
            (string) $response->headers->get('Content-Type')
        );
        $this->assertStringNotContainsString('historical-backlog-event', $response->streamedContent());
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
        $response->assertJsonPath('invite.event_image_url', 'https://example.org/thumb.jpg');
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

    public function test_authenticated_inviter_can_fetch_pending_and_accepted_sent_invites_for_occurrence(): void
    {
        $secondReceiver = $this->createAccountUser('Second Accepted Receiver');
        $occurrenceId = $this->firstOccurrenceId($this->event);

        Sanctum::actingAs($this->sender, ['*']);
        $pendingInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');
        $acceptedInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($secondReceiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondReceiver, ['*']);
        $this->postJson("{$this->base_api_tenant}invites/{$acceptedInviteId}/accept", [])
            ->assertOk();

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->getJson("{$this->base_api_tenant}invites/sent-statuses?occurrence_id={$occurrenceId}&event_id={$this->event->_id}");

        $response->assertOk();
        $response->assertJsonPath('data.event_id', (string) $this->event->_id);
        $response->assertJsonPath('data.occurrence_id', $occurrenceId);
        $this->assertNull($response->json('data.summary'));
        $response->assertJsonPath('metadata.truncated', false);
        $this->assertIsString($response->json('metadata.request_id'));

        $items = collect($response->json('data.items'))->keyBy('invite_id');
        $this->assertSame('pending', $items[$pendingInviteId]['status'] ?? null);
        $this->assertSame('accepted', $items[$acceptedInviteId]['status'] ?? null);
        $this->assertSame('visible', $items[$pendingInviteId]['ui_visibility'] ?? null);
        $this->assertSame('visible', $items[$acceptedInviteId]['ui_visibility'] ?? null);
        $this->assertSame('account_profile:'.$this->accountProfileIdFor($this->receiver), $items[$pendingInviteId]['recipient_key'] ?? null);
        $this->assertSame($this->accountProfileIdFor($this->receiver), $items[$pendingInviteId]['receiver_account_profile_id'] ?? null);
        $this->assertSame((string) $this->receiver->_id, $items[$pendingInviteId]['receiver_user_id'] ?? null);
        $this->assertSame('Receiver User', $items[$pendingInviteId]['display_name'] ?? null);
        $this->assertNotEmpty($items[$pendingInviteId]['sent_at'] ?? null);
        $this->assertNull($items[$pendingInviteId]['responded_at'] ?? null);
        $this->assertNotEmpty($items[$acceptedInviteId]['responded_at'] ?? null);
    }

    public function test_sent_invite_reads_include_account_profile_principal_invites_issued_by_authenticated_user(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $senderProfile = AccountProfile::query()->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Sender Profile Principal',
            'created_by' => (string) $this->sender->_id,
            'created_by_type' => 'tenant',
            'updated_by' => (string) $this->sender->_id,
            'updated_by_type' => 'tenant',
            'is_active' => true,
        ]);
        $senderProfileId = (string) $senderProfile->_id;
        $receiverProfileId = $this->accountProfileIdFor($this->receiver);

        Sanctum::actingAs($this->sender, ['*']);
        $inviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'account_profile_id' => $senderProfileId,
            'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $receiverProfileId],
            ],
        ])->assertOk()->json('created.0.invite_id');

        $edge = InviteEdge::query()->find($inviteId);
        $this->assertSame('account_profile', data_get($edge?->inviter_principal, 'kind'));
        $this->assertSame($senderProfileId, data_get($edge?->inviter_principal, 'principal_id'));
        $this->assertSame((string) $this->sender->_id, (string) $edge?->issued_by_user_id);

        $statuses = $this->getJson("{$this->base_api_tenant}invites/sent-statuses?occurrence_id={$occurrenceId}");
        $statuses->assertOk();
        $statuses->assertJsonCount(1, 'data.items');
        $statuses->assertJsonPath('data.items.0.invite_id', $inviteId);
        $statuses->assertJsonPath('data.items.0.receiver_account_profile_id', $receiverProfileId);

        $summary = $this->getJson("{$this->base_api_tenant}invites/sent-summary?occurrence_id={$occurrenceId}");
        $summary->assertOk();
        $summary->assertJsonPath('data.summary.pending', 1);
        $summary->assertJsonPath('data.summary.total_sent', 1);
        $summary->assertJsonPath('data.preview.0.invite_id', $inviteId);
    }

    public function test_sent_invite_statuses_are_scoped_to_authenticated_inviter(): void
    {
        $secondInviter = $this->createAccountUser('Second Status Inviter');
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $receiverAccountProfileId = $this->accountProfileIdFor($this->receiver);

        Sanctum::actingAs($this->sender, ['*']);
        $senderInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $receiverAccountProfileId],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondInviter, ['*']);
        $secondInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $receiverAccountProfileId],
            ],
        ])->assertOk()->json('created.0.invite_id');

        $senderInvite = InviteEdge::query()->find($senderInviteId);
        $secondInvite = InviteEdge::query()->find($secondInviteId);
        $this->assertSame('pending', (string) $senderInvite?->status);
        $this->assertSame('pending', (string) $secondInvite?->status);
        $this->assertNull($senderInvite?->supersession_reason);
        $this->assertNull($secondInvite?->supersession_reason);

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->getJson("{$this->base_api_tenant}invites/sent-statuses?occurrence_id={$occurrenceId}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.invite_id', $senderInviteId);
        $response->assertJsonPath('data.items.0.receiver_account_profile_id', $receiverAccountProfileId);
    }

    public function test_sent_invite_statuses_reject_cross_tenant_account_token(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $token = $this->app->make(TenantScopedAccessTokenService::class)
            ->issueForAccountUser($this->sender, 'sent-status-primary-token', ['*'])
            ->plainTextToken;
        $headers = [
            'Authorization' => "Bearer {$token}",
            'Content-Type' => 'application/json',
        ];

        $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}invites",
            data: [
                'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
                'recipients' => [
                    ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
                ],
            ],
            headers: $headers,
        )->assertOk();

        $primaryResponse = $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}invites/sent-statuses?occurrence_id={$occurrenceId}",
            headers: $headers,
        );
        $primaryResponse->assertOk();
        $primaryResponse->assertJsonCount(1, 'data.items');

        $secondaryTenant = $this->ensureCanonicalTenantExists($this->landlord->tenant_secondary);
        $secondaryHost = "{$secondaryTenant->subdomain}.{$this->host}";
        $crossTenantResponse = $this->json(
            method: 'get',
            uri: "http://{$secondaryHost}/api/v1/invites/sent-statuses?occurrence_id={$occurrenceId}",
            headers: $headers,
        );

        $this->assertContains($crossTenantResponse->status(), [401, 403]);
        $this->assertNull($crossTenantResponse->json('data'));
    }

    public function test_sent_invite_statuses_reject_client_controlled_inviter_identity(): void
    {
        $secondInviter = $this->createAccountUser('Rejected Client Inviter');
        $occurrenceId = $this->firstOccurrenceId($this->event);

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->getJson(
            "{$this->base_api_tenant}invites/sent-statuses?occurrence_id={$occurrenceId}&inviter_id={$secondInviter->_id}&issued_by_user_id={$secondInviter->_id}"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'client_inviter_identity_forbidden');
    }

    public function test_sent_invite_statuses_reject_event_only_and_occurrence_event_mismatch_requests(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $anotherEvent = $this->createEvent();

        Sanctum::actingAs($this->sender, ['*']);

        $eventOnlyResponse = $this->getJson("{$this->base_api_tenant}invites/sent-statuses?event_id={$this->event->_id}");
        $eventOnlyResponse->assertStatus(422);
        $eventOnlyResponse->assertJsonPath('error.code', 'occurrence_id_required');

        $mismatchResponse = $this->getJson("{$this->base_api_tenant}invites/sent-statuses?occurrence_id={$occurrenceId}&event_id={$anotherEvent->_id}");
        $mismatchResponse->assertStatus(422);
        $mismatchResponse->assertJsonPath('error.code', 'occurrence_event_mismatch');
    }

    public function test_sent_invite_statuses_include_declined_and_hidden_superseded_actionability(): void
    {
        $secondInviter = $this->createAccountUser('Winner Inviter');
        $declinedReceiver = $this->createAccountUser('Declined Receiver');
        $occurrenceId = $this->firstOccurrenceId($this->event);

        Sanctum::actingAs($this->sender, ['*']);
        $supersededInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');
        $declinedInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($declinedReceiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($secondInviter, ['*']);
        $winningInviteId = (string) $this->postJson("{$this->base_api_tenant}invites", [
            'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
            'recipients' => [
                ['receiver_account_profile_id' => $this->accountProfileIdFor($this->receiver)],
            ],
        ])->json('created.0.invite_id');

        Sanctum::actingAs($this->receiver, ['*']);
        $this->postJson("{$this->base_api_tenant}invites/{$winningInviteId}/accept", [])
            ->assertOk();

        Sanctum::actingAs($declinedReceiver, ['*']);
        $this->postJson("{$this->base_api_tenant}invites/{$declinedInviteId}/decline", [])
            ->assertOk();

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->getJson("{$this->base_api_tenant}invites/sent-statuses?occurrence_id={$occurrenceId}");

        $response->assertOk();
        $this->assertNull($response->json('data.summary'));

        $items = collect($response->json('data.items'))->keyBy('invite_id');
        $this->assertSame('declined', $items[$declinedInviteId]['status'] ?? null);
        $this->assertSame('visible', $items[$declinedInviteId]['ui_visibility'] ?? null);
        $this->assertTrue((bool) ($items[$declinedInviteId]['blocks_reinvite'] ?? false));
        $this->assertSame('declined', $items[$declinedInviteId]['counts_bucket'] ?? null);
        $this->assertNotEmpty($items[$declinedInviteId]['responded_at'] ?? null);

        $this->assertSame('superseded', $items[$supersededInviteId]['status'] ?? null);
        $this->assertSame('hidden', $items[$supersededInviteId]['ui_visibility'] ?? null);
        $this->assertTrue((bool) ($items[$supersededInviteId]['blocks_reinvite'] ?? false));
        $this->assertSame('none', $items[$supersededInviteId]['counts_bucket'] ?? null);
        $this->assertSame('other_invite_credited', $items[$supersededInviteId]['supersession_reason'] ?? null);
    }

    public function test_sent_invite_statuses_use_bounded_direct_lookup_without_recipient_n_plus_one(): void
    {
        $secondReceiver = $this->createAccountUser('N Plus One Receiver');
        $thirdReceiver = $this->createAccountUser('Third Status Receiver');
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $profileIds = [
            $this->accountProfileIdFor($this->receiver),
            $this->accountProfileIdFor($secondReceiver),
            $this->accountProfileIdFor($thirdReceiver),
        ];

        Sanctum::actingAs($this->sender, ['*']);
        foreach ($profileIds as $profileId) {
            $this->postJson("{$this->base_api_tenant}invites", [
                'target_ref' => $this->targetRefForOccurrence($this->event, $occurrenceId),
                'recipients' => [
                    ['receiver_account_profile_id' => $profileId],
                ],
            ])->assertOk();
        }

        DB::connection('tenant')->flushQueryLog();
        DB::connection('tenant')->enableQueryLog();

        $query = http_build_query([
            'occurrence_id' => $occurrenceId,
            'recipient_account_profile_ids' => $profileIds,
        ]);
        $response = $this->getJson("{$this->base_api_tenant}invites/sent-statuses?{$query}");

        $response->assertOk();
        $response->assertJsonCount(3, 'data.items');

        $queries = collect(DB::connection('tenant')->getQueryLog());
        $profileQueries = $queries->filter(
            static fn (array $queryLog): bool => str_contains(json_encode($queryLog), 'account_profiles')
        );
        $inviteQueries = $queries->filter(
            static fn (array $queryLog): bool => str_contains(json_encode($queryLog), 'invite_edges')
        );

        $this->assertLessThanOrEqual(1, $profileQueries->count(), 'Recipient profiles must be projected in one bulk lookup.');
        $this->assertGreaterThanOrEqual(1, $inviteQueries->count(), 'Sent-status lookup must query invite_edges directly.');
        $this->assertTrue(
            $inviteQueries->contains(static fn (array $queryLog): bool => str_contains(json_encode($queryLog), $occurrenceId)),
            'Sent-status invite_edges query must be occurrence-scoped.',
        );

        $findSentStatusIndex = static fn () => collect(DB::connection('tenant')->getCollection('invite_edges')->listIndexes())
            ->first(static fn ($index): bool => (string) ($index['name'] ?? '') === 'idx_invite_edges_sent_status_inviter_occurrence');
        $normalizeIndexKeys = static fn ($index): array => json_decode(json_encode($index['key'] ?? []), true);
        $expectedRebuiltIndexKeys = [
            'issued_by_user_id' => 1,
            'event_id' => 1,
            'occurrence_id' => 1,
            'created_at' => -1,
            '_id' => -1,
        ];
        $expectedPreviousIndexKeys = [
            'issued_by_user_id' => 1,
            'event_id' => 1,
            'occurrence_id' => 1,
            'inviter_principal.kind' => 1,
            'inviter_principal.principal_id' => 1,
            'created_at' => -1,
            '_id' => -1,
        ];

        $sentStatusIndex = $findSentStatusIndex();
        $this->assertNotNull($sentStatusIndex, 'Sent-status lookup must have a dedicated occurrence-scoped index.');
        $this->assertSame(
            $expectedRebuiltIndexKeys,
            $normalizeIndexKeys($sentStatusIndex),
            'Sent-status index must keep equality filters before deterministic sort keys.'
        );

        $migrationSource = (string) file_get_contents(
            base_path('packages/belluga/belluga_invites/database/migrations/2026_05_23_000300_add_sent_status_inviter_occurrence_index.php')
        );
        $this->assertStringContainsString('idx_invite_edges_sent_status_inviter_occurrence', $migrationSource);
        $this->assertStringContainsString("'issued_by_user_id' => 1", $migrationSource);
        $this->assertStringContainsString("'event_id' => 1", $migrationSource);
        $this->assertStringContainsString("'occurrence_id' => 1", $migrationSource);
        $this->assertStringContainsString("'created_at' => -1", $migrationSource);

        $rebuildMigrationSource = (string) file_get_contents(
            base_path('packages/belluga/belluga_invites/database/migrations/2026_05_25_000100_rebuild_sent_status_inviter_occurrence_index.php')
        );
        $expectedIndexOrder = [
            "'issued_by_user_id' => 1",
            "'event_id' => 1",
            "'occurrence_id' => 1",
            "'created_at' => -1",
            "'_id' => -1",
        ];
        $previousPosition = -1;

        foreach ($expectedIndexOrder as $expectedIndexFragment) {
            $position = strpos($rebuildMigrationSource, $expectedIndexFragment);

            $this->assertNotFalse($position, "Corrected sent-status index is missing {$expectedIndexFragment}.");
            $this->assertGreaterThan(
                $previousPosition,
                $position,
                "Corrected sent-status index must keep {$expectedIndexFragment} after the previous key."
            );
            $previousPosition = $position;
        }

        $this->assertStringContainsString(
            "'inviter_principal.kind' => 1",
            $rebuildMigrationSource,
            'Rollback must restore the previous sent-status index shape.'
        );

        $rebuildMigration = require base_path(
            'packages/belluga/belluga_invites/database/migrations/2026_05_25_000100_rebuild_sent_status_inviter_occurrence_index.php'
        );

        try {
            $rebuildMigration->down();
            $rolledBackSentStatusIndex = $findSentStatusIndex();

            $this->assertNotNull($rolledBackSentStatusIndex, 'Rollback must restore the previous sent-status index.');
            $this->assertSame(
                $expectedPreviousIndexKeys,
                $normalizeIndexKeys($rolledBackSentStatusIndex),
                'Rollback must restore the exact previous sent-status index key order.'
            );
        } finally {
            $rebuildMigration->up();
        }

        $rebuiltSentStatusIndex = $findSentStatusIndex();
        $this->assertNotNull($rebuiltSentStatusIndex, 'Reapplying the migration must restore the corrected sent-status index.');
        $this->assertSame(
            $expectedRebuiltIndexKeys,
            $normalizeIndexKeys($rebuiltSentStatusIndex),
            'Reapplying the migration must restore the corrected sent-status index key order.'
        );
    }

    public function test_sent_invite_summary_returns_exact_counts_over_more_than_200_sent_invites(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $now = Carbon::now();

        for ($index = 0; $index < 205; $index++) {
            InviteEdge::query()->create([
                'event_id' => (string) $this->event->_id,
                'occurrence_id' => $occurrenceId,
                'receiver_user_id' => (string) new \MongoDB\BSON\ObjectId,
                'receiver_account_profile_id' => (string) new \MongoDB\BSON\ObjectId,
                'inviter_principal' => [
                    'kind' => 'user',
                    'principal_id' => (string) $this->sender->_id,
                ],
                'issued_by_user_id' => (string) $this->sender->_id,
                'status' => $index < 203 ? 'pending' : 'accepted',
                'credited_acceptance' => $index >= 203,
                'source' => 'direct_invite',
                'created_at' => $now->copy()->subSeconds($index),
                'updated_at' => $now->copy()->subSeconds($index),
                'accepted_at' => $index >= 203 ? $now->copy()->subSeconds($index) : null,
            ]);
        }

        Sanctum::actingAs($this->sender, ['*']);
        $response = $this->getJson(
            "{$this->base_api_tenant}invites/sent-summary?occurrence_id={$occurrenceId}&event_id={$this->event->_id}&preview_limit=5"
        );

        $response->assertOk();
        $response->assertJsonPath('data.event_id', (string) $this->event->_id);
        $response->assertJsonPath('data.occurrence_id', $occurrenceId);
        $response->assertJsonPath('data.summary.pending', 203);
        $response->assertJsonPath('data.summary.accepted', 2);
        $response->assertJsonPath('data.summary.declined', 0);
        $response->assertJsonPath('data.summary.terminal_hidden', 0);
        $response->assertJsonPath('data.summary.total_visible', 205);
        $response->assertJsonPath('data.summary.total_sent', 205);
        $response->assertJsonCount(5, 'data.preview');
        $response->assertJsonPath('metadata.preview_limit', 5);
        $this->assertIsString($response->json('metadata.request_id'));
    }

    public function test_sent_invite_summary_rejects_event_only_and_occurrence_event_mismatch_requests(): void
    {
        $occurrenceId = $this->firstOccurrenceId($this->event);
        $anotherEvent = $this->createEvent();

        Sanctum::actingAs($this->sender, ['*']);

        $eventOnlyResponse = $this->getJson("{$this->base_api_tenant}invites/sent-summary?event_id={$this->event->_id}");
        $eventOnlyResponse->assertStatus(422);
        $eventOnlyResponse->assertJsonPath('error.code', 'occurrence_id_required');

        $mismatchResponse = $this->getJson("{$this->base_api_tenant}invites/sent-summary?occurrence_id={$occurrenceId}&event_id={$anotherEvent->_id}");
        $mismatchResponse->assertStatus(422);
        $mismatchResponse->assertJsonPath('error.code', 'occurrence_event_mismatch');
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

    private function seedPushRuntimeReady(): void
    {
        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);

        TenantPushSettings::create([
            'firebase' => [
                'apiKey' => 'key',
                'appId' => 'app',
                'projectId' => 'project',
                'messagingSenderId' => 'sender',
                'storageBucket' => 'bucket',
            ],
            'push' => [
                'enabled' => true,
                'max_ttl_days' => 30,
            ],
        ]);
    }

    private function registerActivePushToken(AccountUser $user, string $pushToken): void
    {
        PushDevice::query()->create([
            'tenant_id' => (string) (Tenant::current()?->_id ?? Tenant::current()?->id ?? ''),
            'account_user_id' => (string) $user->_id,
            'account_ids' => $user->getAccessToIds(),
            'device_id' => 'device-'.Str::random(6),
            'platform' => 'android',
            'push_token' => $pushToken,
            'is_active' => true,
            'last_registered_at' => Carbon::now(),
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
                'type' => 'image',
                'data' => [
                    'url' => 'https://example.org/thumb.jpg',
                ],
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

    private function useMongoQueueRuntimeForTest(): void
    {
        config([
            'queue.default' => 'mongodb',
            'queue.connections.mongodb.connection' => 'mongodb',
            'queue.connections.mongodb.collection' => 'jobs',
            'queue.connections.mongodb.queue' => 'default',
            'queue.failed.driver' => 'null',
        ]);

        app('db')
            ->connection('mongodb')
            ->table('jobs')
            ->delete();
    }

    private function queueJobCount(): int
    {
        return (int) app('db')
            ->connection('mongodb')
            ->table('jobs')
            ->count();
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
