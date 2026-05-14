<?php

namespace Tests\Api\v1\Tenants\Auth;

use App\Models\Landlord\Tenant;
use App\Application\Push\PushChannelNamingService;
use Belluga\PushHandler\Contracts\PushTopicTransportContract;
use Belluga\PushHandler\Models\Tenants\PushCredential;
use Belluga\PushHandler\Models\Tenants\PushDevice;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Support\Str;
use Tests\Fakes\FakePushTopicTransport;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class ApiV1PushRegisterTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    private FakePushTopicTransport $topicTransport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);

        $this->topicTransport = new FakePushTopicTransport();
        $this->app->instance(PushTopicTransportContract::class, $this->topicTransport);
    }

    public function test_push_register_returns_ok(): void
    {
        $headers = $this->issueAnonymousHeaders();

        $response = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}push/register",
            data: [
                'device_id' => 'device-123',
                'platform' => 'ios',
                'push_token' => 'token-123',
            ],
            headers: $headers
        );

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        Tenant::current()?->makeCurrent();

        $device = PushDevice::query()
            ->where('device_id', 'device-123')
            ->firstOrFail();

        $this->assertSame('token-123', $device->push_token);
        $this->assertSame('ios', $device->platform);
        $this->assertTrue((bool) $device->is_active);
    }

    public function test_push_unregister_returns_ok(): void
    {
        $headers = $this->issueAnonymousHeaders();

        $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}push/register",
            data: [
                'device_id' => 'device-456',
                'platform' => 'android',
                'push_token' => 'token-456',
            ],
            headers: $headers
        )->assertStatus(200);

        $response = $this->json(
            method: 'delete',
            uri: "{$this->base_api_tenant}push/unregister",
            data: [
                'device_id' => 'device-456',
            ],
            headers: $headers
        );

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);

        Tenant::current()?->makeCurrent();

        $device = PushDevice::query()
            ->where('device_id', 'device-456')
            ->firstOrFail();

        $this->assertFalse((bool) $device->is_active);
        $this->assertNotNull($device->invalidated_at);
    }

    public function test_push_register_and_unregister_sync_topic_membership_lifecycle(): void
    {
        $this->seedPushRuntimeReady();
        $headers = $this->issueAnonymousHeaders();
        Tenant::current()?->makeCurrent();
        $allUsersTopic = $this->app->make(PushChannelNamingService::class)->allUsersTopic();

        $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}push/register",
            data: [
                'device_id' => 'device-topic-123',
                'platform' => 'ios',
                'push_token' => 'token-topic-123',
            ],
            headers: $headers
        )->assertStatus(200);

        $this->assertSame([['token-topic-123']], $this->topicTransport->unsubscribeAll);
        $this->assertContains([
            'topic' => $allUsersTopic,
            'tokens' => ['token-topic-123'],
        ], $this->topicTransport->subscriptions);

        $this->json(
            method: 'delete',
            uri: "{$this->base_api_tenant}push/unregister",
            data: [
                'device_id' => 'device-topic-123',
            ],
            headers: $headers
        )->assertStatus(200);

        $this->assertSame(
            [['token-topic-123'], ['token-topic-123']],
            $this->topicTransport->unsubscribeAll
        );
    }

    private function issueAnonymousHeaders(): array
    {
        $response = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}anonymous/identities",
            data: [
                'device_name' => 'Push Register Test Device',
                'fingerprint' => [
                    'hash' => hash('sha256', 'push-register-'.Str::uuid()->toString()),
                    'user_agent' => 'PushRegisterTest/1.0',
                ],
            ],
        );

        $response->assertStatus(201);

        return [
            'Authorization' => 'Bearer '.$response->json('data.token'),
            'Content-Type' => 'application/json',
        ];
    }

    private function seedPushRuntimeReady(): void
    {
        PushCredential::query()->delete();
        TenantPushSettings::query()->delete();

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
}
