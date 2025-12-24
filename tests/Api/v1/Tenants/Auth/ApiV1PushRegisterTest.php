<?php

namespace Tests\Api\v1\Tenants\Auth;

use Tests\TestCaseTenant;

class ApiV1PushRegisterTest extends TestCaseTenant
{
    public function testPushRegisterReturnsOk(): void
    {
        $response = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}v1/push/register",
            data: [
                'device_id' => 'device-123',
                'platform' => 'ios',
                'push_token' => 'token-123',
            ],
            headers: $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
    }
}
