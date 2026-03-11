<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Profiles;

use App\Application\Profiles\LandlordProfileService;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordProfileServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private LandlordProfileService $service;

    private LandlordUser $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();
        $this->service = $this->app->make(LandlordProfileService::class);

        $this->user = LandlordUser::create([
            'name' => 'Landlord Admin',
            'emails' => [$this->uniqueEmail()],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
        ]);
    }

    public function test_update_profile(): void
    {
        $updated = $this->service->updateProfile($this->user, ['name' => 'Updated Landlord']);

        $this->assertSame('Updated Landlord', $updated->name);
    }

    public function test_update_profile_rejects_empty(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->updateProfile($this->user, []);
    }

    public function test_update_password(): void
    {
        $this->service->updatePassword($this->user, 'Another!234');

        $this->assertTrue(Hash::check('Another!234', (string) $this->user->fresh()->password));
    }

    public function test_add_email(): void
    {
        $updated = $this->service->addEmail($this->user, $this->uniqueEmail());

        $this->assertGreaterThan(1, count($updated->emails));
    }

    public function test_remove_email_prevents_removing_last(): void
    {
        $this->expectException(HttpResponseException::class);

        $this->service->removeEmail($this->user, $this->user->emails[0]);
    }

    public function test_add_phones(): void
    {
        $updated = $this->service->addPhones($this->user, [$this->uniquePhoneNumber()]);

        $this->assertNotEmpty($updated->phones);
    }

    public function test_add_phones_rejects_invalid(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->addPhones($this->user, ['invalid-phone']);
    }

    private function uniqueEmail(): string
    {
        return sprintf('landlord-%s@example.org', Str::uuid());
    }

    private function uniquePhoneNumber(): string
    {
        return '+1'.random_int(2000000000, 2999999999);
    }
}
