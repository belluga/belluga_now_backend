<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Profiles;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Profiles\TenantProfileService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class TenantProfileServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private TenantProfileService $service;

    private AccountUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();
        $this->initializeSystem();

        $this->service = $this->app->make(TenantProfileService::class);

        [$account] = $this->seedAccountWithRole(['account-users:*']);
        $account->makeCurrent();

        $this->user = $account->users()->create([
            'name' => 'Tenant User',
            'emails' => [$this->uniqueEmail()],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
        ]);
    }

    public function testUpdateProfileUpdatesAttributes(): void
    {
        $updated = $this->service->updateProfile($this->user, ['name' => 'Updated Name']);

        $this->assertSame('Updated Name', $updated->name);
    }

    public function testUpdateProfileRejectsEmptyPayload(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->updateProfile($this->user, []);
    }

    public function testUpdatePasswordHashesSecret(): void
    {
        $this->service->updatePassword($this->user, 'Another!234');

        $this->assertTrue(Hash::check('Another!234', (string) $this->user->fresh()->password));
    }

    public function testAddEmailAppendsNewAddress(): void
    {
        $newEmail = $this->uniqueEmail();

        $updated = $this->service->addEmail($this->user, $newEmail);

        $this->assertContains($newEmail, $updated->emails);
    }

    public function testRemoveEmailPreventsRemovingLast(): void
    {
        $this->expectException(HttpResponseException::class);

        $this->service->removeEmail($this->user, $this->user->emails[0]);
    }

    public function testAddPhonesStoresParsedNumbers(): void
    {
        $updated = $this->service->addPhones($this->user, [$this->uniquePhoneNumber()]);

        $this->assertNotEmpty($updated->phones);
    }

    public function testAddPhonesRejectsInvalid(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->addPhones($this->user, ['invalid-phone']);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Sigma', 'subdomain' => 'tenant-sigma'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-sigma.test']
        );

        $service->initialize($payload);
        Tenant::query()->firstOrFail()->makeCurrent();
    }

    private function uniquePhoneNumber(): string
    {
        return '+55' . random_int(1000000000, 1999999999);
    }

    private function uniqueEmail(): string
    {
        return sprintf('user-%s@example.org', Str::uuid());
    }
}
