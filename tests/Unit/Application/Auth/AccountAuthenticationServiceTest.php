<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Auth;

use App\Application\Auth\AccountAuthenticationService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;
use Illuminate\Support\Str;

class AccountAuthenticationServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private AccountAuthenticationService $service;

    private AccountUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();
        $this->initializeSystem();

        $this->service = $this->app->make(AccountAuthenticationService::class);

        [$account, $role] = $this->seedAccountWithRole(['account-users:*']);
        $account->makeCurrent();

        $this->user = $account->users()->create([
            'name' => 'Tenant Operator',
            'emails' => [$this->uniqueEmail()],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
        ]);
    }

    public function testLoginReturnsToken(): void
    {
        $result = $this->service->login($this->user->emails[0], 'Secret!234', 'api-client');

        $this->assertSame($this->user->emails[0], $result->user->emails[0]);
        $this->assertNotEmpty($result->plainTextToken);
    }

    public function testLoginThrowsWhenCredentialsInvalid(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        $this->service->login($this->user->emails[0], 'wrong-password', 'api-client');
    }

    public function testLogoutDeletesDeviceTokens(): void
    {
        $result = $this->service->login($this->user->emails[0], 'Secret!234', 'api-client');

        $this->service->logout($result->user, false, 'api-client');

        $this->assertCount(0, $result->user->tokens);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Xi', 'subdomain' => 'tenant-xi'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-xi.test']
        );

        $service->initialize($payload);

        Tenant::query()->firstOrFail()->makeCurrent();
    }

    private function uniqueEmail(): string
    {
        return sprintf('tenant-operator-%s@example.org', Str::uuid());
    }
}
