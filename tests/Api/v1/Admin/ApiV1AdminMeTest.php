<?php

namespace Tests\Api\v1\Admin;

use Tests\TestCaseAuthenticated;

class ApiV1AdminMeTest extends TestCaseAuthenticated
{
    public function test_admin_me_returns_profile_payload(): void
    {
        $response = $this->json(
            method: 'get',
            uri: 'admin/api/v1/me',
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
    }
}
