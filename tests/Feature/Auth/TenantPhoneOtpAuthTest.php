<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\AccountProfiles\AccountProfileBootstrapService;
use App\Application\Accounts\AccountUserService;
use App\Application\Auth\PhoneOtpReviewAccessCodeHasher;
use App\Application\Push\ContactEnteredAppPushDeliveryService;
use App\Application\Social\InviteablePeopleProjectionService;
use App\Jobs\Auth\DeliverPhoneOtpWebhookJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\PhoneOtpChallenge;
use App\Models\Tenants\TenantSettings;
use Belluga\Invites\Application\Contacts\ContactImportService;
use Belluga\Invites\Models\Tenants\ContactHashDirectory;
use Belluga\PushHandler\Models\Tenants\PushCredential;
use Belluga\PushHandler\Models\Tenants\PushDevice;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\SeedsTenantAccounts;

class TenantPhoneOtpAuthTest extends TestCaseTenant
{
    use SeedsTenantAccounts;

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
        PushMessage::query()->delete();
        PushCredential::query()->delete();
        PushDevice::query()->delete();
        TenantPushSettings::query()->delete();
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
        $response->assertHeader('X-Api-Security-Domain', 'tenant_public_phone_otp_challenge');
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
        $response->assertHeader('X-Api-Security-Domain', 'tenant_public_phone_otp_challenge');
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
        $challenge->assertHeader('X-Api-Security-Domain', 'tenant_public_phone_otp_challenge');

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
        $verify->assertHeader('X-Api-Security-Domain', 'tenant_public_phone_otp_verify');
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
            'phones' => ['+5527999997788'],
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

    public function test_phone_otp_verification_updates_all_directory_matches_but_bounds_advisory_projection_fanout(): void
    {
        Queue::fake();
        $this->configureReviewAccess('+5527999990422', '123456');
        ContactHashDirectory::query()->delete();

        $phoneHash = hash('sha256', '5527999990422');
        for ($index = 0; $index < 101; $index++) {
            $importer = AccountUser::create([
                'identity_state' => 'registered',
                'name' => "High fanout importer {$index}",
                'phones' => [sprintf('+552799%07d', 8000000 + $index)],
            ]);
            ContactHashDirectory::create([
                'importing_user_id' => (string) $importer->_id,
                'contact_hash' => $phoneHash,
                'type' => 'phone',
            ]);
        }

        $projection = Mockery::mock(app(InviteablePeopleProjectionService::class))->makePartial();
        $projection->shouldReceive('refreshForUser')
            ->once()
            ->with(Mockery::on(static fn (mixed $user): bool => $user instanceof AccountUser
                && in_array('+5527999990422', (array) $user->phones, true)));
        $projection->shouldReceive('refreshForUserIds')
            ->once()
            ->with(Mockery::on(static fn (mixed $ids): bool => is_array($ids)
                && count($ids) === 100
                && count(array_unique($ids)) === 100));
        app()->instance(InviteablePeopleProjectionService::class, $projection);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990422',
            'device_name' => 'otp-high-fanout',
        ])->assertStatus(202);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990422',
            'code' => '123456',
            'device_name' => 'otp-high-fanout',
        ])->assertOk();

        $this->assertSame(101, ContactHashDirectory::query()
            ->where('contact_hash', $phoneHash)
            ->where('matched_user_id', (string) $verify->json('data.user_id'))
            ->count());
    }

    public function test_phone_otp_verification_authors_contact_entered_app_push_once_for_newly_matched_prior_importers(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');
        $this->seedPushRuntimeReady();
        ContactHashDirectory::query()->delete();

        $viewer = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Prior Import Viewer',
            'phones' => ['+5527999997777'],
        ]);
        $this->registerActivePushToken($viewer, 'prior-import-viewer-token');

        $targetPhone = '+55 27 99999-0421';
        $targetPhoneHash = hash('sha256', '5527999990421');

        app(ContactImportService::class)->import($viewer, [
            'contacts' => [
                [
                    'type' => 'phone',
                    'hash' => $targetPhoneHash,
                ],
            ],
        ]);

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
            'phone' => '+5527999990421',
            'code' => $otpCode,
            'device_name' => 'android-release-smoke',
        ]);
        $verify->assertStatus(200);

        $matchedUserId = (string) $verify->json('data.user_id');
        $this->assertNotSame('', $matchedUserId);

        $message = PushMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame('contact_entered_app', (string) $message->type);
        $this->assertSame([(string) $viewer->_id], data_get($message->audience, 'user_ids'));
        $this->assertSame('contact_entered_app', data_get($message->fcm_options, 'data.event'));
        $this->assertSame($matchedUserId, data_get($message->fcm_options, 'data.matched_user_id'));
        $this->assertSame('Contato entrou no app', data_get($message->fcm_options, 'notification.title'));

        $repeatChallenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => $targetPhone,
            'device_name' => 'android-release-smoke',
        ]);
        $repeatChallenge->assertStatus(202);

        $repeatOtpCode = null;
        Queue::assertPushed(DeliverPhoneOtpWebhookJob::class, function (DeliverPhoneOtpWebhookJob $job) use (&$repeatOtpCode): bool {
            $repeatOtpCode = $job->code();

            return true;
        });
        $this->assertIsString($repeatOtpCode);

        $repeatVerify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $repeatChallenge->json('data.challenge_id'),
            'phone' => '+5527999990421',
            'code' => $repeatOtpCode,
            'device_name' => 'android-release-smoke',
        ]);
        $repeatVerify->assertStatus(200);

        $this->assertSame(1, PushMessage::query()->count());
    }

    public function test_phone_otp_verification_still_returns_token_when_advisory_contact_push_delivery_throws(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');

        $mock = Mockery::mock(ContactEnteredAppPushDeliveryService::class);
        $mock->shouldReceive('sendToImporters')
            ->once()
            ->andThrow(new \RuntimeException('forced advisory push failure'));
        $this->app->instance(ContactEnteredAppPushDeliveryService::class, $mock);

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0439',
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
            'phone' => '+5527999990439',
            'code' => $otpCode,
            'device_name' => 'android-release-smoke',
        ]);

        $verify->assertStatus(200);
        $verify->assertHeader('X-Api-Security-Domain', 'tenant_public_phone_otp_verify');
        $this->assertNotEmpty($verify->json('data.token'));
        $this->assertNotEmpty($verify->json('data.user_id'));

        $storedChallenge = PhoneOtpChallenge::query()->findOrFail($challenge->json('data.challenge_id'));
        $this->assertSame(PhoneOtpChallenge::STATUS_VERIFIED, (string) $storedChallenge->status);
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

    public function test_phone_otp_verification_accepts_real_generated_webhook_code_for_existing_user_merge_flow(): void
    {
        $this->configureOtpWebhook('https://integrations.example/otp');
        $anonymous = $this->issueAnonymousIdentity('phone-otp-real-code-existing-user');
        $existingUser = AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Existing Phone OTP User',
            'phones' => ['+5527999990432'],
            'credentials' => [],
        ]);

        $deliveredPayload = null;
        $deliveredCode = null;

        Http::fake(function (Request $request) use (&$deliveredPayload, &$deliveredCode) {
            $deliveredPayload = $request->data();
            $deliveredCode = $deliveredPayload['code'] ?? null;

            return Http::response(['ok' => true], 200);
        });

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0432',
            'device_name' => 'android-release-smoke',
        ]);
        $challenge->assertStatus(202);

        Http::assertSentCount(1);
        $this->assertIsArray($deliveredPayload);
        $this->assertSame('phone_otp.challenge', $deliveredPayload['type'] ?? null);
        $this->assertSame('whatsapp', $deliveredPayload['channel'] ?? null);
        $this->assertSame('+5527999990432', $deliveredPayload['phone'] ?? null);
        $this->assertSame($challenge->json('data.challenge_id'), $deliveredPayload['challenge_id'] ?? null);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $deliveredCode);

        $verify = $this->postJson("{$this->base_api_tenant}auth/otp/verify", [
            'challenge_id' => $challenge->json('data.challenge_id'),
            'phone' => '+5527999990432',
            'code' => $deliveredCode,
            'device_name' => 'android-release-smoke',
            'anonymous_user_ids' => [$anonymous['user_id']],
        ]);

        $verify->assertStatus(200);
        $verify->assertJsonPath('data.user_id', (string) $existingUser->_id);
        $verify->assertJsonPath('data.identity_state', 'registered');
        $this->assertNotEmpty($verify->json('data.token'));

        $existingUser->refresh();
        $this->assertContains($anonymous['user_id'], $existingUser->merged_source_ids);
    }

    public function test_phone_otp_verification_for_multi_account_user_issues_unbound_public_token(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');

        [$accountA, $roleA] = $this->seedAccountWithRole(['account-users:view', 'telemetry-settings:update']);
        [$accountB, $roleB] = $this->seedAccountWithRole(['events:create']);
        $phone = '+5527999990440';
        $email = uniqid('multi-account-otp-', true).'@example.org';
        AccountUser::create([
            'identity_state' => 'registered',
            'name' => 'Multi Account OTP',
            'phones' => [$phone],
            'emails' => [$email],
            'credentials' => [],
        ]);

        $accountUserService = app(AccountUserService::class);
        $accountUserService->create($accountA, [
            'name' => 'Multi Account OTP',
            'email' => $email,
            'password' => 'Secret!234',
        ], (string) $roleA->_id);
        $accountUserService->create($accountB, [
            'name' => 'Multi Account OTP',
            'email' => $email,
            'password' => 'Secret!234',
        ], (string) $roleB->_id);

        Account::current()?->forget();

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => $phone,
            'device_name' => 'multi-account-public-otp',
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
            'phone' => $phone,
            'code' => $otpCode,
            'device_name' => 'multi-account-public-otp',
        ]);

        $verify->assertStatus(200);
        $token = AccountUser::query()
            ->findOrFail((string) $verify->json('data.user_id'))
            ->tokens()
            ->where('name', 'multi-account-public-otp')
            ->first();

        $this->assertNotNull($token);
        $this->assertNull($token->account_id);
        $this->assertSame([], (array) $token->abilities);

        $settingsResponse = $this
            ->withHeaders(['Authorization' => "Bearer {$verify->json('data.token')}"])
            ->getJson("{$this->base_api_tenant}settings/telemetry");
        $settingsResponse->assertStatus(403);
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
        $response->assertHeader('X-Api-Security-Domain', 'tenant_public_phone_otp_challenge');
        $this->assertNotEmpty($response->json('retry_after'));
    }

    public function test_phone_otp_challenge_allows_reissue_after_pending_challenge_expires_even_if_resend_window_is_future(): void
    {
        Queue::fake();
        $this->configureOtpWebhook('https://integrations.example/otp');

        $initial = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990099',
            'device_name' => 'android-release-smoke',
        ]);
        $initial->assertStatus(202);

        $record = PhoneOtpChallenge::query()->findOrFail($initial->json('data.challenge_id'));
        $record->expires_at = now()->subSecond();
        $record->resend_available_at = now()->addMinutes(5);
        $record->save();

        $reissued = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+5527999990099',
            'device_name' => 'android-release-smoke',
        ]);

        $reissued->assertStatus(202);
        $this->assertNotSame($initial->json('data.challenge_id'), $reissued->json('data.challenge_id'));

        $record->refresh();
        $this->assertSame(PhoneOtpChallenge::STATUS_EXPIRED, $record->status);

        Queue::assertPushed(DeliverPhoneOtpWebhookJob::class, 2);
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
        $challenge->assertHeader('X-Api-Security-Domain', 'tenant_public_phone_otp_challenge');

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
        $first->assertHeader('X-Api-Security-Domain', 'tenant_public_phone_otp_verify');

        $second = $this->postJson("{$this->base_api_tenant}auth/otp/verify", $payload);
        $second->assertStatus(422);
        $second->assertHeader('X-Api-Security-Domain', 'tenant_public_phone_otp_verify');
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

    public function test_phone_otp_challenge_serializes_concurrent_requests_per_phone(): void
    {
        $this->configureReviewAccess('+5527999990021', '123456');

        $results = $this->runConcurrentProcesses([
            $this->challengeProcess('+5527999990021', 'otp-concurrency-challenge-1'),
            $this->challengeProcess('+5527999990021', 'otp-concurrency-challenge-2'),
            $this->challengeProcess('+5527999990021', 'otp-concurrency-challenge-3'),
            $this->challengeProcess('+5527999990021', 'otp-concurrency-challenge-4'),
            $this->challengeProcess('+5527999990021', 'otp-concurrency-challenge-5'),
        ]);

        $successes = array_values(array_filter($results, fn (array $result): bool => ($result['ok'] ?? false) === true));
        $cooldowns = array_values(array_filter($results, function (array $result): bool {
            return ($result['ok'] ?? false) === false
                && ($result['exception'] ?? null) === 'App\\Application\\Auth\\PhoneOtpCooldownException';
        }));

        $this->assertCount(1, $successes, json_encode($results, JSON_PRETTY_PRINT));
        $this->assertCount(4, $cooldowns, json_encode($results, JSON_PRETTY_PRINT));

        $pendingChallenges = PhoneOtpChallenge::query()
            ->where('phone', '+5527999990021')
            ->where('status', PhoneOtpChallenge::STATUS_PENDING)
            ->get();

        $this->assertCount(1, $pendingChallenges);
    }

    public function test_phone_otp_verify_allows_only_one_concurrent_successful_consume(): void
    {
        Queue::fake();
        $this->configureReviewAccess('+5527999990022', '123456');

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0022',
            'device_name' => 'otp-concurrency-verify-seed',
        ]);
        $challenge->assertStatus(202);

        $challengeId = (string) $challenge->json('data.challenge_id');
        $results = $this->runConcurrentProcesses([
            $this->verifyProcess($challengeId, '+5527999990022', '123456', 'otp-concurrency-verify-1'),
            $this->verifyProcess($challengeId, '+5527999990022', '123456', 'otp-concurrency-verify-2'),
            $this->verifyProcess($challengeId, '+5527999990022', '123456', 'otp-concurrency-verify-3'),
            $this->verifyProcess($challengeId, '+5527999990022', '123456', 'otp-concurrency-verify-4'),
            $this->verifyProcess($challengeId, '+5527999990022', '123456', 'otp-concurrency-verify-5'),
        ]);

        $successes = array_values(array_filter($results, fn (array $result): bool => ($result['ok'] ?? false) === true));
        $inactiveFailures = array_values(array_filter($results, function (array $result): bool {
            return ($result['ok'] ?? false) === false
                && (($result['errors']['code'][0] ?? null) === 'The OTP challenge is no longer active.');
        }));

        $this->assertCount(1, $successes, json_encode($results, JSON_PRETTY_PRINT));
        $this->assertCount(4, $inactiveFailures, json_encode($results, JSON_PRETTY_PRINT));

        $record = PhoneOtpChallenge::query()->findOrFail($challengeId);
        $this->assertSame(PhoneOtpChallenge::STATUS_VERIFIED, $record->status);
        $this->assertNotNull($record->verified_at);
        $this->assertSame(
            1,
            AccountUser::query()
                ->where('phones', 'all', ['+5527999990022'])
                ->count()
        );
    }

    public function test_phone_otp_verify_keeps_invalid_attempt_lockout_atomic_under_concurrency(): void
    {
        Queue::fake();
        $this->configureReviewAccess('+5527999990023', '123456');

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0023',
            'device_name' => 'otp-concurrency-invalid-seed',
        ]);
        $challenge->assertStatus(202);

        $challengeId = (string) $challenge->json('data.challenge_id');
        $results = $this->runConcurrentProcesses([
            $this->verifyProcess($challengeId, '+5527999990023', '000000', 'otp-concurrency-invalid-1'),
            $this->verifyProcess($challengeId, '+5527999990023', '000000', 'otp-concurrency-invalid-2'),
            $this->verifyProcess($challengeId, '+5527999990023', '000000', 'otp-concurrency-invalid-3'),
            $this->verifyProcess($challengeId, '+5527999990023', '000000', 'otp-concurrency-invalid-4'),
            $this->verifyProcess($challengeId, '+5527999990023', '000000', 'otp-concurrency-invalid-5'),
        ]);

        $successes = array_values(array_filter($results, fn (array $result): bool => ($result['ok'] ?? false) === true));
        $errorMessages = array_map(
            fn (array $result): ?string => $result['errors']['code'][0] ?? null,
            array_values(array_filter($results, fn (array $result): bool => ($result['ok'] ?? false) === false))
        );

        $this->assertCount(0, $successes, json_encode($results, JSON_PRETTY_PRINT));
        $this->assertContains('The OTP code is invalid.', $errorMessages, json_encode($results, JSON_PRETTY_PRINT));

        $record = PhoneOtpChallenge::query()->findOrFail($challengeId);
        $this->assertSame(5, (int) $record->attempts);
        $this->assertSame(PhoneOtpChallenge::STATUS_LOCKED, $record->status);
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

    private function seedPushRuntimeReady(): void
    {
        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);

        $settings = TenantSettings::query()->first() ?? new TenantSettings;
        $settings->firebase = [
            'apiKey' => 'key',
            'androidAppId' => 'android-app',
            'iosAppId' => 'ios-app',
            'projectId' => 'project',
            'messagingSenderId' => 'sender',
            'storageBucket' => 'bucket',
        ];
        $settings->push = [
            'enabled' => true,
            'max_ttl_days' => 30,
        ];
        $settings->save();
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
            'last_registered_at' => now(),
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

    /**
     * @param  list<Process>  $processes
     * @return list<array<string, mixed>>
     */
    private function runConcurrentProcesses(array $processes): array
    {
        foreach ($processes as $process) {
            $process->start();
        }

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $outputLines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $process->getOutput()) ?: [])));
            $jsonLine = end($outputLines);
            $this->assertIsString($jsonLine, $process->getOutput());
            $results[] = json_decode($jsonLine, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    private function challengeProcess(string $phone, string $deviceName): Process
    {
        $payload = var_export([
            'phone' => $phone,
            'device_name' => $deviceName,
        ], true);
        $tenantSlug = var_export($this->tenantModel->slug, true);

        $code = <<<PHP
try {
    \$tenantModel = 'App\\\\Models\\\\Landlord\\\\Tenant';
    \$tenant = \$tenantModel::query()->where('slug', {$tenantSlug})->firstOrFail();
    \$tenant->makeCurrent();
    \$result = app('App\\\\Application\\\\Auth\\\\TenantPhoneOtpAuthService')->challenge({$payload});
    echo json_encode([
        'ok' => true,
        'challenge_id' => \$result->challengeId,
    ], JSON_THROW_ON_ERROR);
} catch (\\Throwable \$exception) {
    \$errors = method_exists(\$exception, 'errors') ? \$exception->errors() : null;
    echo json_encode([
        'ok' => false,
        'exception' => \$exception::class,
        'message' => \$exception->getMessage(),
        'errors' => \$errors,
    ], JSON_THROW_ON_ERROR);
}
PHP;

        return new Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $code], base_path(), null, null, 30);
    }

    private function verifyProcess(string $challengeId, string $phone, string $codeValue, string $deviceName): Process
    {
        $payload = var_export([
            'challenge_id' => $challengeId,
            'phone' => $phone,
            'code' => $codeValue,
            'device_name' => $deviceName,
        ], true);
        $tenantSlug = var_export($this->tenantModel->slug, true);

        $code = <<<PHP
try {
    \$tenantModel = 'App\\\\Models\\\\Landlord\\\\Tenant';
    \$tenant = \$tenantModel::query()->where('slug', {$tenantSlug})->firstOrFail();
    \$tenant->makeCurrent();
    \$result = app('App\\\\Application\\\\Auth\\\\TenantPhoneOtpAuthService')->verify(\$tenant, {$payload});
    echo json_encode([
        'ok' => true,
        'user_id' => (string) (\$result->user->_id ?? ''),
    ], JSON_THROW_ON_ERROR);
} catch (\\Throwable \$exception) {
    \$errors = method_exists(\$exception, 'errors') ? \$exception->errors() : null;
    echo json_encode([
        'ok' => false,
        'exception' => \$exception::class,
        'message' => \$exception->getMessage(),
        'errors' => \$errors,
    ], JSON_THROW_ON_ERROR);
}
PHP;

        return new Process([PHP_BINARY, 'artisan', 'tinker', '--execute', $code], base_path(), null, null, 30);
    }
}
