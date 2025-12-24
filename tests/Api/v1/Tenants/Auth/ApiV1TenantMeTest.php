<?php

namespace Tests\Api\v1\Tenants\Auth;

use Tests\TestCaseTenant;

class ApiV1TenantMeTest extends TestCaseTenant
{
    public function testTenantMeReturnsProfilePayload(): void
    {
        $response = $this->json(
            method: 'get',
            uri: "{$this->base_api_tenant}v1/me",
            headers: $this->getHeaders()
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
