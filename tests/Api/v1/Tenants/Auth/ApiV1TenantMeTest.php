<?php

namespace Tests\Api\v1\Tenants\Auth;

use Tests\TestCaseTenant;
use Tests\Helpers\TenantLabels;

class ApiV1TenantMeTest extends TestCaseTenant
{
    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    public function testTenantMeReturnsProfilePayload(): void
    {
        $email = fake()->unique()->safeEmail();
        $password = 'Secret!234';

        $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/register/password",
            data: [
                'name' => 'Tenant Me User',
                'email' => $email,
                'password' => $password,
            ]
        )->assertStatus(201);

        $login = $this->json(
            method: 'post',
            uri: "{$this->base_api_tenant}auth/login",
            data: [
                'email' => $email,
                'password' => $password,
                'device_name' => 'tenant-me-test',
            ]
        );

        $login->assertStatus(200);
        $token = $login->json('data.token');

        $response = $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}me",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'tenant_id',
            'data' => [
                'user_id',
                'display_name',
                'avatar_url',
                'user_level',
                'privacy_mode',
                'social_score' => [
                    'invites_accepted',
                    'presences_confirmed',
                    'rank_label',
                ],
                'counters' => [
                    'pending_invites',
                    'confirmed_events',
                    'favorites',
                ],
                'role_claims' => [
                    'is_partner',
                    'is_curator',
                    'is_verified',
                ],
            ],
        ]);
        $response->assertJsonPath('data.user_level', 'basic');
        $response->assertJsonPath('data.privacy_mode', 'public');
    }
}
