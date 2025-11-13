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

    public function testIndexFiltersByName(): void
    {
        $this->createLandlordUser('Filter Target', 'filter.target@example.org');
        $this->createLandlordUser('Another Staff', 'another.staff@example.org');

        $response = $this->json(
            'get',
            'admin/api/users?filter[name]=Filter Target',
            [],
            $this->getHeaders()
        );

        $response->assertOk();
        $this->assertSame('Filter Target', $response->json('data.0.name'));
    }

    public function testIndexIgnoresUnsupportedSortAndUsesDefaultOrder(): void
    {
        $this->createLandlordUser('Alpha Staff', 'alpha.staff@example.org');
        $this->createLandlordUser('Zulu Staff', 'zulu.staff@example.org');

        $baseline = $this->json('get', 'admin/api/users', [], $this->getHeaders());
        $fallback = $this->json(
            'get',
            'admin/api/users?sort=-unsupported',
            [],
            $this->getHeaders()
        );

        $baselineFirst = $baseline->json('data.0.id');
        $fallbackFirst = $fallback->json('data.0.id');

        $this->assertNotNull($baselineFirst);
        $this->assertSame($baselineFirst, $fallbackFirst);
    }

    private function createLandlordUser(string $name, string $email): void
    {
        $payload = [
            'name' => $name,
            'email' => $email,
            'password' => 'Secret!234',
            'password_confirmation' => 'Secret!234',
            'device_name' => 'support-device',
            'role_id' => $this->landlord->role_users_manager->id,
        ];

        $this->json('post', 'admin/api/users', $payload, $this->getHeaders())->assertStatus(201);
    }
}
