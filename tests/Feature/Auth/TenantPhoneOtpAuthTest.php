<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\AccountProfiles\AccountProfileBootstrapService;
use App\Application\Auth\PhoneOtpReviewAccessCodeHasher;
use App\Jobs\Auth\DeliverPhoneOtpWebhookJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\PhoneOtpChallenge;
use App\Models\Tenants\TenantSettings;
use Belluga\Invites\Application\Contacts\ContactImportService;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class TenantPhoneOtpAuthTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private Tenant $tenantModel;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forgetCurrent();
        $this->tenantModel = Tenant::query()
            ->where('slug', $this->tenant->slug)
            ->firstOrFail();
        $this->tenantModel->makeCurrent();

        PhoneOtpChallenge::query()->delete();
        TenantSettings::query()->delete();
    }

    public function test_phone_otp_challenge_defaults_to_whatsapp_primary_webhook(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/sms');

        $response = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 (27) 99999-0000',
            'device_name' => 'android-release-smoke',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.phone', '+5527999990000');
        $response->assertJsonPath('data.delivery.channel', 'whatsapp');
        $this->assertNotEmpty($response->json('data.challenge_id'));
        $this->assertNotEmpty($response->json('data.expires_at'));
        $this->assertNotEmpty($response->json('data.resend_available_at'));

        Queue::assertPushed(
            DeliverPhoneOtpWebhookJob::class,
            fn (DeliverPhoneOtpWebhookJob $job): bool => $job->webhookUrl() === 'https://integrations.example/whatsapp'
                && $job->channel() === 'whatsapp'
                && $job->phone() === '+5527999990000'
                && strlen($job->code()) === 6
                && $job->queue === 'otp'
        );
    }

    public function test_phone_otp_challenge_explicit_sms_uses_secondary_otp_webhook(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/sms');

        $response = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 (27) 99999-0006',
            'device_name' => 'android-release-smoke',
            'delivery_channel' => 'sms',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.delivery.channel', 'sms');

        $challenge = PhoneOtpChallenge::query()->findOrFail($response->json('data.challenge_id'));
        $this->assertSame('sms', $challenge->delivery_channel);
        $this->assertSame('https://integrations.example/sms', $challenge->delivery_webhook_url);

        Queue::assertPushed(
            DeliverPhoneOtpWebhookJob::class,
            fn (DeliverPhoneOtpWebhookJob $job): bool => $job->webhookUrl() === 'https://integrations.example/sms'
                && $job->channel() === 'sms'
                && $job->phone() === '+5527999990006'
                && $job->queue === 'otp'
        );
    }

    public function test_phone_otp_challenge_explicit_sms_requires_secondary_otp_webhook(): void
    {
        TenantSettings::create([
            'tenant_public_auth' => [
                'enabled_methods' => ['phone_otp'],
            ],
            'outbound_integrations' => [
                'whatsapp' => [
                    'webhook_url' => 'https://integrations.example/whatsapp',
                ],
                'otp' => [
                    'use_whatsapp_webhook' => true,
                    'delivery_channel' => 'whatsapp',
                    'ttl_minutes' => 10,
                    'resend_cooldown_seconds' => 60,
                    'max_attempts' => 5,
                ],
            ],
        ]);

        $response = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990007',
            'device_name' => 'android-release-smoke',
            'delivery_channel' => 'sms',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.webhook_url.0', 'Configure an SMS OTP webhook URL before starting SMS OTP delivery.');
    }

    public function test_phone_otp_challenge_preserves_query_string_secondary_sms_webhook_url(): void
    {
        Queue::fake();
        $webhookUrl = 'https://n8ntech.unifast.com.br/webhook/otp?channel=sms';
        $this->configureOtpWebhook($webhookUrl);

        $response = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 (27) 99999-0010',
            'device_name' => 'android-release-smoke',
            'delivery_channel' => 'sms',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.delivery.channel', 'sms');

        $challenge = PhoneOtpChallenge::query()->findOrFail($response->json('data.challenge_id'));
        $this->assertSame($webhookUrl, $challenge->delivery_webhook_url);

        Queue::assertPushed(
            DeliverPhoneOtpWebhookJob::class,
            fn (DeliverPhoneOtpWebhookJob $job): bool => $job->webhookUrl() === $webhookUrl
                && $job->channel() === 'sms'
                && $job->phone() === '+5527999990010'
                && $job->queue === 'otp'
        );
    }

    public function test_phone_otp_verification_promotes_identity_and_materializes_contact_match_hash(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');
        $anonymous = $this->issueAnonymousIdentity('phone-otp-merge-source');

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0001',
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);

        $otpCode = null;
        Queue::assertPushed(DeliverPhoneOtpWebhookJob::class, function (DeliverPhoneOtpWebhookJob $job) use (&$otpCode): bool {
            $otpCode = $job->code();

            return true;
        });
        $this->assertIsString($otpCode);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990001',
            'code' => $otpCode,
            'device_name' => 'android-release-smoke',
            'anonymous_user_ids' => [$anonymous['user_id']],
        ]);

        $verify->assertStatus(200);
        $verify->assertJsonPath('data.identity_state', 'registered');
        $this->assertNotEmpty($verify->json('data.token'));

        $userId = (string) $verify->json('data.user_id');
        $user = AccountUser::query()->findOrFail($userId);
        $this->assertSame('registered', $user->identity_state);
        $this->assertContains('+5527999990001', $user->phones);
        $this->assertContains(hash('sha256', '5527999990001'), $user->phone_hashes);
        $this->assertContains($anonymous['user_id'], $user->merged_source_ids);

        $viewer = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Contact Import Viewer',
            'phones' => ['+5527999999999'],
        ]);
        $matches = app(ContactImportService::class)->import($viewer, [
            'contacts' => [
                [
                    'type' => 'phone',
                    'hash' => hash('sha256', '5527999990001'),
                ],
            ],
        ]);

        $this->assertSame($userId, $matches['matches'][0]['user_id'] ?? null);
    }

    public function test_phone_otp_verification_rematches_existing_contact_hash_directory_rows(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');
        ContactHashDirectory::query()->delete();

        $viewer = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Prior Import Viewer',
            'phones' => ['+5527999997777'],
        ]);
        $targetPhone = '+55 27 99999-0420';
        $targetPhoneHash = hash('sha256', '5527999990420');

        $initialImport = app(ContactImportService::class)->import($viewer, [
            'contacts' => [
                [
                    'type' => 'phone',
                    'hash' => $targetPhoneHash,
                ],
            ],
        ]);

        $this->assertSame([], $initialImport['matches']);
        $directoryRow = ContactHashDirectory::query()
            ->where('importing_user_id', (string) $viewer->_id)
            ->where('contact_hash', $targetPhoneHash)
            ->first();
        $this->assertNotNull($directoryRow);
        $this->assertNull($directoryRow->matched_user_id);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => $targetPhone,
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);

        $otpCode = null;
        Queue::assertPushed(DeliverPhoneOtpWebhookJob::class, function (DeliverPhoneOtpWebhookJob $job) use (&$otpCode): bool {
            $otpCode = $job->code();

            return true;
        });
        $this->assertIsString($otpCode);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990420',
            'code' => $otpCode,
            'device_name' => 'android-release-smoke',
        ]);
        $verify->assertStatus(200);

        $targetUserId = (string) $verify->json('data.user_id');
        $directoryRow->refresh();
        $this->assertSame($targetUserId, (string) $directoryRow->matched_user_id);

        Sanctum::actingAs($viewer->fresh(), ['*']);
        $inviteables = $this->getJson("{$this->base_api_tenant}contacts/inviteables");
        $inviteables->assertOk();
        $inviteables->assertJsonPath('items.0.user_id', $targetUserId);
        $inviteables->assertJsonPath('items.0.inviteable_reasons.0', 'contact_match');
    }

    public function test_phone_otp_verification_migrates_anonymous_contact_imports_to_registered_viewer(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');
        ContactHashDirectory::query()->delete();

        $target = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Existing Device Contact',
            'phones' => ['+55 27 99999-0431'],
            'emails' => [],
            'fingerprints' => [],
            'credentials' => [],
            'consents' => [],
        ]);
        app(AccountProfileBootstrapService::class)->ensurePersonalAccount($target);
        $target = $target->fresh();
        $targetPhoneHash = hash('sha256', '5527999990431');

        $anonymous = $this->issueAnonymousIdentity('phone-otp-contact-import-viewer');
        $anonymousUser = AccountUser::query()->findOrFail($anonymous['user_id']);
        $initialImport = app(ContactImportService::class)->import($anonymousUser, [
            'contacts' => [
                [
                    'type' => 'phone',
                    'hash' => $targetPhoneHash,
                ],
            ],
        ]);

        $this->assertSame((string) $target->_id, $initialImport['matches'][0]['user_id'] ?? null);
        $this->assertNotNull(ContactHashDirectory::query()
            ->where('importing_user_id', $anonymous['user_id'])
            ->where('contact_hash', $targetPhoneHash)
            ->first());

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0430',
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);

        $otpCode = null;
        Queue::assertPushed(DeliverPhoneOtpWebhookJob::class, function (DeliverPhoneOtpWebhookJob $job) use (&$otpCode): bool {
            $otpCode = $job->code();

            return true;
        });
        $this->assertIsString($otpCode);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990430',
            'code' => $otpCode,
            'device_name' => 'android-release-smoke',
            'anonymous_user_ids' => [$anonymous['user_id']],
        ]);
        $verify->assertStatus(200);

        $viewerId = (string) $verify->json('data.user_id');
        $this->assertNotSame('', $viewerId);
        $this->assertNull(ContactHashDirectory::query()
            ->where('importing_user_id', $anonymous['user_id'])
            ->where('contact_hash', $targetPhoneHash)
            ->first());

        $migratedRow = ContactHashDirectory::query()
            ->where('importing_user_id', $viewerId)
            ->where('contact_hash', $targetPhoneHash)
            ->first();
        $this->assertNotNull($migratedRow);
        $this->assertSame((string) $target->_id, (string) $migratedRow->matched_user_id);

        Sanctum::actingAs(AccountUser::query()->findOrFail($viewerId), ['*']);
        $inviteables = $this->getJson("{$this->base_api_tenant}contacts/inviteables");
        $inviteables->assertOk();
        $inviteables->assertJsonPath('items.0.user_id', (string) $target->_id);
        $inviteables->assertJsonPath('items.0.inviteable_reasons.0', 'contact_match');
    }

    public function test_phone_otp_challenge_can_use_whatsapp_webhook_when_otp_url_is_not_configured(): void
    {
        Queue::fake();

        TenantSettings::create([
            'tenant_public_auth' => [
                'enabled_methods' => ['phone_otp'],
            ],
            'outbound_integrations' => [
                'whatsapp' => [
                    'webhook_url' => 'https://integrations.example/whatsapp',
                ],
                'otp' => [
                    'use_whatsapp_webhook' => true,
                    'delivery_channel' => 'whatsapp',
                    'ttl_minutes' => 10,
                    'resend_cooldown_seconds' => 60,
                    'max_attempts' => 5,
                ],
            ],
        ]);

        $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990004',
            'device_name' => 'android-release-smoke',
        ])->assertStatus(202);

        Queue::assertPushed(
            DeliverPhoneOtpWebhookJob::class,
            fn (DeliverPhoneOtpWebhookJob $job): bool => $job->webhookUrl() === 'https://integrations.example/whatsapp'
                && $job->channel() === 'whatsapp'
                && $job->phone() === '+5527999990004'
        );
    }

    public function test_phone_otp_challenge_can_use_legacy_otp_webhook_for_whatsapp_when_configured_that_way(): void
    {
        Queue::fake();

        TenantSettings::create([
            'tenant_public_auth' => [
                'enabled_methods' => ['phone_otp'],
            ],
            'outbound_integrations' => [
                'otp' => [
                    'webhook_url' => 'https://integrations.example/legacy-whatsapp',
                    'delivery_channel' => 'whatsapp',
                    'ttl_minutes' => 10,
                    'resend_cooldown_seconds' => 60,
                    'max_attempts' => 5,
                ],
            ],
        ]);

        $response = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990008',
            'device_name' => 'android-release-smoke',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.delivery.channel', 'whatsapp');

        Queue::assertPushed(
            DeliverPhoneOtpWebhookJob::class,
            fn (DeliverPhoneOtpWebhookJob $job): bool => $job->webhookUrl() === 'https://integrations.example/legacy-whatsapp'
                && $job->channel() === 'whatsapp'
                && $job->phone() === '+5527999990008'
        );
    }

    public function test_phone_otp_challenge_requires_configured_webhook_url(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990002',
            'device_name' => 'android-release-smoke',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.webhook_url.0', 'Configure an OTP or WhatsApp webhook URL before starting OTP delivery.');
    }

    public function test_phone_otp_challenge_enforces_resend_cooldown(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');

        $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990003',
            'device_name' => 'android-release-smoke',
        ])->assertStatus(202);

        $response = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990003',
            'device_name' => 'android-release-smoke',
        ]);

        $response->assertStatus(429);
        $this->assertNotEmpty($response->json('retry_after'));
    }

    public function test_phone_otp_verify_cannot_consume_same_challenge_twice(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990011',
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);

        $otpCode = null;
        Queue::assertPushed(DeliverPhoneOtpWebhookJob::class, function (DeliverPhoneOtpWebhookJob $job) use (&$otpCode): bool {
            $otpCode = $job->code();

            return true;
        });
        $this->assertIsString($otpCode);

        $payload = [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990011',
            'code' => $otpCode,
            'device_name' => 'android-release-smoke',
        ];

        $first = $this->postJson("{$this->base_api_tenant}auth/otp/verify", $payload);
        $first->assertStatus(200);

        $second = $this->postJson("{$this->base_api_tenant}auth/otp/verify", $payload);
        $second->assertStatus(422);
        $second->assertJsonPath('errors.code.0', 'The OTP challenge is no longer active.');
    }

    public function test_phone_otp_verify_locks_challenge_after_max_invalid_attempts(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990012',
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);

        $otpCode = null;
        Queue::assertPushed(DeliverPhoneOtpWebhookJob::class, function (DeliverPhoneOtpWebhookJob $job) use (&$otpCode): bool {
            $otpCode = $job->code();

            return true;
        });
        $this->assertIsString($otpCode);

        $payload = [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990012',
            'code' => '000000',
            'device_name' => 'android-release-smoke',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->postJson("{$this->base_api_tenant}auth/otp/verify", $payload);
            $response->assertStatus(422);
            $response->assertJsonPath('errors.code.0', 'The OTP code is invalid.');
        }

        $record = PhoneOtpChallenge::query()->findOrFail($challenge->json('data.challenge_id'));
        $this->assertSame(5, (int) $record->attempts);
        $this->assertSame(PhoneOtpChallenge::STATUS_LOCKED, $record->status);

        $validAfterLock = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990012',
            'code' => $otpCode,
            'device_name' => 'android-release-smoke',
        ]);
        $validAfterLock->assertStatus(422);
        $validAfterLock->assertJsonPath('errors.code.0', 'The OTP challenge is no longer active.');
    }

    public function test_phone_otp_review_access_verifies_allowlisted_phone_without_webhook_delivery(): void
    {
        Queue::fake();
        $this->configureReviewAccess('+5527999990013', '123456');

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0013',
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);
        Queue::assertNotPushed(DeliverPhoneOtpWebhookJob::class);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990013',
            'code' => '123456',
            'device_name' => 'android-release-smoke',
        ]);

        $verify->assertStatus(200);
        $verify->assertJsonPath('data.identity_state', 'registered');
        $this->assertNotEmpty($verify->json('data.token'));
    }

    public function test_phone_otp_review_access_rejects_non_allowlisted_phone(): void
    {
        Queue::fake();
        TenantSettings::create([
            'tenant_public_auth' => [
                'enabled_methods' => ['phone_otp'],
            ],
            'phone_otp_review_access' => [
                'phone_e164' => '+5527999990014',
                'code_hash' => app(PhoneOtpReviewAccessCodeHasher::class)->make('123456'),
            ],
            'outbound_integrations' => [
                'whatsapp' => [
                    'webhook_url' => 'https://integrations.example/whatsapp',
                ],
                'otp' => [
                    'webhook_url' => 'https://integrations.example/otp',
                    'use_whatsapp_webhook' => true,
                    'delivery_channel' => 'whatsapp',
                    'ttl_minutes' => 10,
                    'resend_cooldown_seconds' => 60,
                    'max_attempts' => 5,
                ],
            ],
        ]);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0015',
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990015',
            'code' => '123456',
            'device_name' => 'android-release-smoke',
        ]);

        $verify->assertStatus(422);
        $verify->assertJsonPath('errors.code.0', 'The OTP code is invalid.');
    }

    public function test_phone_otp_review_access_rejects_disabled_review_user(): void
    {
        Queue::fake();
        $this->configureReviewAccess('+5527999990016', '123456');

        $user = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Disabled Reviewer',
            'phones' => ['+5527999990016'],
            'credentials' => [],
        ]);
        $user->delete();

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0016',
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);
        Queue::assertNotPushed(DeliverPhoneOtpWebhookJob::class);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990016',
            'code' => '123456',
            'device_name' => 'android-release-smoke',
        ]);

        $verify->assertStatus(422);
        $verify->assertJsonPath('errors.phone.0', 'This phone number cannot be used to authenticate.');
    }

    public function test_phone_otp_verify_rejects_expired_challenge(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990005',
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);

        $otpCode = null;
        Queue::assertPushed(DeliverPhoneOtpWebhookJob::class, function (DeliverPhoneOtpWebhookJob $job) use (&$otpCode): bool {
            $otpCode = $job->code();

            return true;
        });
        $this->assertIsString($otpCode);

        $record = PhoneOtpChallenge::query()->findOrFail($challenge->json('data.challenge_id'));
        $record->expires_at = now()->subMinute();
        $record->save();

        $response = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990005',
            'code' => $otpCode,
            'device_name' => 'android-release-smoke',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code.0', 'The OTP challenge has expired.');
    }

    /**
     * @return array{user_id:string, token:string}
     */
    private function issueAnonymousIdentity(string $deviceName): array
    {
        $response = $this->postJson("{$this->base_api_tenant}anonymous/identities", [
            'device_name' => $deviceName,
            'fingerprint' => [
                'hash' => hash('sha256', $deviceName),
                'user_agent' => 'TenantPhoneOtpAuthTest/1.0',
                'locale' => 'pt-BR',
            ],
            'metadata' => [
                'source' => 'feature-test',
            ],
        ]);
        $response->assertStatus(201);

        return [
            'user_id' => (string) $response->json('data.user_id'),
            'token' => (string) $response->json('data.token'),
        ];
    }

    private function configureOtpWebhook(string $url): void
    {
        TenantSettings::create([
            'tenant_public_auth' => [
                'enabled_methods' => ['phone_otp'],
            ],
            'outbound_integrations' => [
                'whatsapp' => [
                    'webhook_url' => 'https://integrations.example/whatsapp',
                ],
                'otp' => [
                    'webhook_url' => $url,
                    'use_whatsapp_webhook' => true,
                    'delivery_channel' => 'whatsapp',
                    'ttl_minutes' => 10,
                    'resend_cooldown_seconds' => 60,
                    'max_attempts' => 5,
                ],
            ],
        ]);
    }

    private function configureReviewAccess(string $phone, string $code): void
    {
        TenantSettings::create([
            'tenant_public_auth' => [
                'enabled_methods' => ['phone_otp'],
            ],
            'phone_otp_review_access' => [
                'phone_e164' => $phone,
                'code_hash' => app(PhoneOtpReviewAccessCodeHasher::class)->make($code),
            ],
        ]);
    }
}
