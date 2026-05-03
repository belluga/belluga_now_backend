<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Jobs\Auth\DeliverPhoneOtpWebhookJob;
use App\Jobs\Telemetry\DeliverTelemetryEventJob;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\PhoneOtpChallenge;
use App\Models\Tenants\TenantSettings;
use Illuminate\Support\Facades\Queue;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class TenantPhoneOtpTelemetryTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forgetCurrent();
        Tenant::query()
            ->where('slug', $this->tenant->slug)
            ->firstOrFail()
            ->makeCurrent();

        PhoneOtpChallenge::query()->delete();
        TenantSettings::query()->delete();
    }

    public function test_phone_otp_challenge_emits_pre_auth_funnel_telemetry(): void
    {
        Queue::fake();
        $this->configureOtpWebhookAndTelemetry();

        $response = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 (27) 99999-0099',
            'device_name' => 'android-release-smoke',
        ]);

        $response->assertStatus(202);

        Queue::assertPushed(
            DeliverTelemetryEventJob::class,
            function (DeliverTelemetryEventJob $job): bool {
                $envelope = $this->telemetryEnvelope($job);

                return ($envelope['event'] ?? null) === 'otp_challenge_started'
                    && ($envelope['actor']['type'] ?? null) === 'phone_otp_challenge'
                    && ($envelope['actor']['id'] ?? null) === ($envelope['metadata']['challenge_id'] ?? null)
                    && ($envelope['metadata']['delivery_channel'] ?? null) === 'whatsapp'
                    && ! array_key_exists('user_id', $envelope['metadata'] ?? []);
            }
        );
    }

    public function test_phone_otp_verification_emits_verified_and_merge_funnel_telemetry(): void
    {
        Queue::fake();
        $this->configureOtpWebhookAndTelemetry();
        $anonymous = $this->issueAnonymousIdentity('phone-otp-telemetry-merge-source');

        $challenge = $this->postJson("{$this->base_api_tenant}auth/otp/challenge", [
            'phone' => '+55 27 99999-0100',
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
            'phone' => '+5527999990100',
            'code' => $otpCode,
            'device_name' => 'android-release-smoke',
            'anonymous_user_ids' => [$anonymous['user_id']],
        ]);

        $verify->assertStatus(200);
        $verify->assertJsonPath('data.identity_state', 'registered');
        $userId = (string) $verify->json('data.user_id');

        Queue::assertPushed(
            DeliverTelemetryEventJob::class,
            function (DeliverTelemetryEventJob $job) use ($userId): bool {
                $envelope = $this->telemetryEnvelope($job);

                return ($envelope['event'] ?? null) === 'otp_verified'
                    && ($envelope['actor']['type'] ?? null) === 'user'
                    && ($envelope['actor']['id'] ?? null) === $userId
                    && ($envelope['target']['type'] ?? null) === 'user'
                    && ($envelope['target']['id'] ?? null) === $userId
                    && ($envelope['metadata']['user_id'] ?? null) === $userId
                    && ($envelope['metadata']['identity_state'] ?? null) === 'registered';
            }
        );

        Queue::assertPushed(
            DeliverTelemetryEventJob::class,
            function (DeliverTelemetryEventJob $job) use ($userId): bool {
                $envelope = $this->telemetryEnvelope($job);

                return ($envelope['event'] ?? null) === 'auth_merge_completed'
                    && ($envelope['actor']['type'] ?? null) === 'user'
                    && ($envelope['actor']['id'] ?? null) === $userId
                    && ($envelope['metadata']['user_id'] ?? null) === $userId
                    && ($envelope['metadata']['source_count'] ?? null) === 1
                    && ($envelope['metadata']['source_kind'] ?? null) === 'anonymous';
            }
        );
    }

    private function configureOtpWebhookAndTelemetry(): void
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
                    'webhook_url' => 'https://integrations.example/otp',
                    'use_whatsapp_webhook' => true,
                    'delivery_channel' => 'whatsapp',
                    'ttl_minutes' => 10,
                    'resend_cooldown_seconds' => 60,
                    'max_attempts' => 5,
                ],
            ],
            'telemetry' => [
                'location_freshness_minutes' => 5,
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
     * @return array{user_id:string, token:string}
     */
    private function issueAnonymousIdentity(string $deviceName): array
    {
        $response = $this->postJson("{$this->base_api_tenant}anonymous/identities", [
            'device_name' => $deviceName,
            'fingerprint' => [
                'hash' => hash('sha256', $deviceName),
                'user_agent' => 'TenantPhoneOtpTelemetryTest/1.0',
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
}
