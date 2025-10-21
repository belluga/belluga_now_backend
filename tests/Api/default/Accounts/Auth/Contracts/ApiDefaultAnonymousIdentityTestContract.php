<?php

namespace Tests\Api\default\Accounts\Auth\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\UserLabels;
use Tests\TestCaseTenant;

abstract class ApiDefaultAnonymousIdentityTestContract extends TestCaseTenant
{
    protected function anonymousIdentityLabel(): UserLabels
    {
        return new UserLabels("{$this->tenant->subdomain}.anonymous.identity");
    }

    protected function anonymousIdentityEndpoint(): string
    {
        return sprintf('%sv1/anonymous/identities', $this->base_api_tenant);
    }

    protected function issueAnonymousIdentity(array $payload): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: $this->anonymousIdentityEndpoint(),
            data: $payload
        );
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
}

