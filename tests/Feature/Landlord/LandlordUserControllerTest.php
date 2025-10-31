<?php

declare(strict_types=1);

namespace Tests\Feature\Landlord;

use Tests\TestCaseAuthenticated;
use Tests\Traits\SeedsLandlordSupportRoles;

class LandlordUserControllerTest extends TestCaseAuthenticated
{
    use SeedsLandlordSupportRoles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSupportRoles();
    }

    public function testCreatesLandlordUser(): void
    {
        $payload = [
            'name' => 'Support Staff',
            'email' => 'support.staff@example.org',
            'password' => 'Secret!234',
            'password_confirmation' => 'Secret!234',
            'device_name' => 'support-device',
            'role_id' => $this->landlord->role_users_manager->id,
        ];

        $response = $this->json('post', 'admin/api/users', $payload, $this->getHeaders());

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Support Staff');
    }
}
