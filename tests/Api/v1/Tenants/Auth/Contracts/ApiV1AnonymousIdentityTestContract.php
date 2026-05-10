<?php

namespace Tests\Api\v1\Tenants\Auth\Contracts;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\UserLabels;
use Tests\TestCaseTenant;

abstract class ApiV1AnonymousIdentityTestContract extends TestCaseTenant
{
    private Tenant $tenantModel;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forgetCurrent();
        $this->tenantModel = $this->ensureCanonicalTenantExists($this->tenant);
        $this->tenantModel->makeCurrent();
    }

    protected function tearDown(): void
    {
        $this->tenantModel->makeCurrent();
        Tenant::forgetCurrent();

        parent::tearDown();
    }

    protected function anonymousIdentityLabel(): UserLabels
    {
        return new UserLabels("{$this->tenant->subdomain}.anonymous.identity");
    }

    protected function anonymousIdentityEndpoint(): string
    {
        return sprintf('%sanonymous/identities', $this->base_api_tenant);
    }

    protected function issueAnonymousIdentity(array $payload, array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->json(
            method: 'post',
            uri: $this->anonymousIdentityEndpoint(),
            data: $payload
        );
    }

    private function throttleTestNamespace(): string
    {
        return str_replace('\\', '.', static::class);
    }

    private function throttleTestRemoteAddr(): string
    {
        $seed = crc32(static::class);
        $thirdOctet = (($seed >> 8) & 0xff) ?: 1;
        $fourthOctet = ($seed & 0xff) ?: 1;

        return sprintf('127.0.%d.%d', $thirdOctet, $fourthOctet);
    }

    public function testAnonymousIdentityIssuance(): void
    {
        $payload = [
            'device_name' => 'integration-device',
            'fingerprint' => [
                'hash' => hash('sha256', 'integration-device'),
                'user_agent' => 'IntegrationTest/1.0',
                'locale' => 'en-US',
            ],
            'metadata' => [
                'source' => 'integration-tests',
            ],
        ];

        $response = $this->issueAnonymousIdentity($payload);

        $response->assertStatus(201);
        $response->assertHeader('X-Api-Security-Domain', 'tenant_public_anonymous_identity');
        $response->assertJsonStructure([
            'data' => [
                'user_id',
                'identity_state',
                'token',
                'abilities',
            ],
        ]);
        $response->assertJsonPath('data.identity_state', 'anonymous');

        $label = $this->anonymousIdentityLabel();
        $label->user_id = $response->json('data.user_id');
        $label->token = $response->json('data.token');
        $label->password = $payload['fingerprint']['hash'];
    }

    public function testAnonymousIdentityRouteAppliesLiveSecurityDomainThrottling(): void
    {
        $overrides = array_map(function (array $override): array {
            return match ((string) ($override['domain'] ?? '')) {
                'tenant_public_anonymous_identity' => [...$override, 'requests_per_minute' => 1],
                default => $override,
            };
        }, (array) config('api_security.route_overrides', []));

        config()->set('api_security.route_overrides', $overrides);
        config()->set('api_security.lifecycle.enabled', false);
        config()->set('api_security.levels.L2.requests_per_minute', 9999);
        Cache::flush();
        $namespace = $this->throttleTestNamespace();
        $remoteAddr = $this->throttleTestRemoteAddr();

        $first = $this->issueAnonymousIdentity([
            'device_name' => 'integration-device-throttle',
            'fingerprint' => [
                'hash' => hash('sha256', "{$namespace}.anonymous-throttle-one"),
                'user_agent' => 'IntegrationTest/1.0',
            ],
        ], ['REMOTE_ADDR' => $remoteAddr]);
        $first->assertStatus(201);
        $first->assertHeader('X-Api-Security-Domain', 'tenant_public_anonymous_identity');

        $second = $this->issueAnonymousIdentity([
            'device_name' => 'integration-device-throttle-2',
            'fingerprint' => [
                'hash' => hash('sha256', "{$namespace}.anonymous-throttle-two"),
                'user_agent' => 'IntegrationTest/1.0',
            ],
        ], ['REMOTE_ADDR' => $remoteAddr]);
        $second->assertStatus(429);
        $second->assertHeader('X-Api-Security-Domain', 'tenant_public_anonymous_identity');
    }

    public function testAnonymousIdentityReissueReturnsSameUser(): void
    {
        $label = $this->anonymousIdentityLabel();
        $fingerprintHash = $label->password ?: hash('sha256', 'integration-device');

        $payload = [
            'device_name' => 'integration-device-reissue',
            'fingerprint' => [
                'hash' => $fingerprintHash,
                'user_agent' => 'IntegrationTest/1.0',
            ],
            'metadata' => [
                'source' => 'integration-tests',
            ],
        ];

        $response = $this->issueAnonymousIdentity($payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.identity_state', 'anonymous');
        $this->assertEquals($label->user_id, $response->json('data.user_id'));

        $label->token = $response->json('data.token');
    }

    public function testAnonymousIdentityReissueUpdatesExistingFingerprintEntry(): void
    {
        $label = $this->anonymousIdentityLabel();
        $hash = $label->password ?: hash('sha256', 'integration-device');

        // First issuance to ensure the identity exists
        $this->issueAnonymousIdentity([
            'device_name' => 'integration-device-a',
            'fingerprint' => [
                'hash' => $hash,
                'user_agent' => 'IntegrationTest/1.0',
            ],
        ])->assertStatus(201);

        // Reissue with same hash should not create a new fingerprint entry
        $response = $this->issueAnonymousIdentity([
            'device_name' => 'integration-device-b',
            'fingerprint' => [
                'hash' => $hash,
                'user_agent' => 'IntegrationTest/2.0',
            ],
        ]);

        $response->assertStatus(201);

        $userId = $response->json('data.user_id');
        $this->assertNotEmpty($userId);

        // Load the user and assert only one fingerprint entry exists
        $this->tenantModel->makeCurrent();
        $user = \App\Models\Tenants\AccountUser::query()->where('_id', new \MongoDB\BSON\ObjectId($userId))->firstOrFail();
        $this->assertIsArray($user->fingerprints ?? []);
        $this->assertCount(1, $user->fingerprints, 'Reissue should update existing entry, not append');
        $this->assertEquals($hash, $user->fingerprints[0]['hash']);
        $this->assertArrayHasKey('first_seen_at', $user->fingerprints[0]);
        $this->assertArrayHasKey('last_seen_at', $user->fingerprints[0]);
    }

    public function testAnonymousIdentityNewFingerprintCreatesNewUser(): void
    {
        $firstHash = hash('sha256', 'integration-device-one');
        $secondHash = hash('sha256', 'integration-device-two');

        $first = $this->issueAnonymousIdentity([
            'device_name' => 'device-one',
            'fingerprint' => [
                'hash' => $firstHash,
                'user_agent' => 'IntegrationTest/1.0',
            ],
        ]);
        $first->assertStatus(201);
        $userId = $first->json('data.user_id');

        $second = $this->issueAnonymousIdentity([
            'device_name' => 'device-two',
            'fingerprint' => [
                'hash' => $secondHash,
                'user_agent' => 'IntegrationTest/1.0',
            ],
        ]);
        $second->assertStatus(201);
        $this->assertNotEquals($userId, $second->json('data.user_id'));
    }
}
