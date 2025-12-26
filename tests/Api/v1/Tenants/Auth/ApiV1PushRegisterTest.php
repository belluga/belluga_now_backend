<?php

namespace Tests\Api\v1\Tenants\Auth;

use Tests\TestCaseTenant;
use Tests\Helpers\TenantLabels;

class ApiV1PushRegisterTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    public function testPushRegisterReturnsOk(): void
    {
        $response = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}push/register",
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

    public function testPushUnregisterReturnsOk(): void
    {
        $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}push/register",
            data: [
                'device_id' => 'device-456',
                'platform' => 'android',
                'push_token' => 'token-456',
            ],
            headers: $this->getHeaders()
        )->assertStatus(200);

        $response = $this->json(
            method: 'delete',
            uri: "{$this->base_api_tenant}push/unregister",
            data: [
                'device_id' => 'device-456',
            ],
            headers: $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
    }
}
