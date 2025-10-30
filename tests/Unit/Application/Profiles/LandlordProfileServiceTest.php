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

    public function testUpdateProfile(): void
    {
        $updated = $this->service->updateProfile($this->user, ['name' => 'Updated Landlord']);

        $this->assertSame('Updated Landlord', $updated->name);
    }

    public function testUpdateProfileRejectsEmpty(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->updateProfile($this->user, []);
    }

    public function testUpdatePassword(): void
    {
        $this->service->updatePassword($this->user, 'Another!234');

        $this->assertTrue(Hash::check('Another!234', (string) $this->user->fresh()->password));
    }

    public function testAddEmail(): void
    {
        $updated = $this->service->addEmail($this->user, $this->uniqueEmail());

        $this->assertGreaterThan(1, count($updated->emails));
    }

    public function testRemoveEmailPreventsRemovingLast(): void
    {
        $this->expectException(HttpResponseException::class);

        $this->service->removeEmail($this->user, $this->user->emails[0]);
    }

    public function testAddPhones(): void
    {
        $updated = $this->service->addPhones($this->user, [$this->uniquePhoneNumber()]);

        $this->assertNotEmpty($updated->phones);
    }

    public function testAddPhonesRejectsInvalid(): void
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
        return '+1' . random_int(2000000000, 2999999999);
    }
}
