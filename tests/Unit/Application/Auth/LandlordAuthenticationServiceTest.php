<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Auth;

use App\Application\Auth\LandlordAuthenticationService;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Landlord\LandlordUser;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordAuthenticationServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private LandlordAuthenticationService $service;

    private LandlordUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();

        $this->service = $this->app->make(LandlordAuthenticationService::class);

        $this->user = LandlordUser::create([
            'name' => 'Landlord Admin',
            'emails' => ['landlord@example.org'],
            'password' => 'Secret!234',
        ]);
    }

    public function testLoginReturnsToken(): void
    {
        $result = $this->service->login('landlord@example.org', 'Secret!234', 'admin-client');

        $this->assertSame('landlord@example.org', $result->user->emails[0]);
        $this->assertNotEmpty($result->plainTextToken);
    }

    public function testLoginThrowsOnInvalidCredentials(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        $this->service->login('landlord@example.org', 'invalid', 'admin-client');
    }

    public function testRegisterCreatesUser(): void
    {
        $result = $this->service->register([
            'name' => 'New Landlord',
            'email' => 'new-landlord@example.org',
            'password' => 'Secret!234',
            'device_name' => 'admin-client',
        ]);

        $this->assertNotEmpty($result->plainTextToken);
        $this->assertTrue(Hash::check('Secret!234', (string) $result->user->password));
    }
}

