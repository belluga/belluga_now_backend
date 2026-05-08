<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Profiles;

use App\Application\Auth\PasswordResetTokenService;
use App\Application\Auth\PasswordResetFlowService;
use App\Application\Profiles\LandlordProfileService;
use App\Models\Landlord\PersonalAccessToken;
use App\Models\Landlord\LandlordUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use MongoDB\BSON\ObjectId;
use RuntimeException;
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
            'identity_state' => 'registered',
        ]);
        $this->user->syncCredential('password', $this->user->emails[0], Hash::make('Secret!234'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
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

    public function test_update_password_synchronizes_all_email_password_credentials_and_removes_legacy_password_state(): void
    {
        $secondaryEmail = $this->uniqueEmail();
        $this->user->emails = [$this->user->emails[0], $secondaryEmail];
        $this->user->save();
        $this->user->syncCredential('password', $this->user->emails[0], Hash::make('Old!234'));

        $this->service->updatePassword($this->user, 'Another!234');

        $this->assertPasswordStateSynchronized($this->user, 'Another!234');
    }

    public function test_reset_password_synchronizes_all_email_password_credentials_and_removes_legacy_password_state(): void
    {
        $secondaryEmail = $this->uniqueEmail();
        $this->user->emails = [$this->user->emails[0], $secondaryEmail];
        $this->user->save();
        $this->user->syncCredential('password', $this->user->emails[0], Hash::make('Old!234'));
        $this->user->createToken('landlord-reset-revocation');

        $token = $this->app->make(PasswordResetTokenService::class)->issueForUser(
            userId: $this->user->id,
            email: $this->user->emails[0],
            broker: PasswordResetTokenService::LANDLORD_USERS_BROKER,
        );

        $this->assertSame(1, $this->landlordTokenCount($this->user));

        $this->service->resetPassword($this->user->emails[0], $token, 'Another!234');

        $this->assertPasswordStateSynchronized($this->user, 'Another!234');
        $this->assertSame(0, $this->landlordTokenCount($this->user));
        $this->assertNull(
            DB::connection('landlord')
                ->table('password_reset_tokens')
                ->where('user_id', $this->user->id)
                ->first()
        );
    }

    public function test_reset_password_burns_the_token_before_password_persistence_failure(): void
    {
        $tokenService = $this->app->make(PasswordResetTokenService::class);
        $token = $tokenService->issueForUser(
            userId: $this->user->id,
            email: $this->user->emails[0],
            broker: PasswordResetTokenService::LANDLORD_USERS_BROKER,
        );

        $service = new class(
            $this->app->make(\App\Application\LandlordUsers\LandlordUserAccessService::class),
            $this->app->make(PasswordResetFlowService::class),
        ) extends LandlordProfileService {
            protected function applyResetPassword(LandlordUser $user, string $password): void
            {
                throw new RuntimeException('Simulated landlord password persistence failure.');
            }
        };

        try {
            $service->resetPassword($this->user->emails[0], $token, 'Another!234');
            $this->fail('Expected simulated landlord password persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated landlord password persistence failure.', $exception->getMessage());
        }

        $this->assertNull(
            DB::connection('landlord')
                ->table('password_reset_tokens')
                ->where('user_id', $this->user->id)
                ->first()
        );

        $this->expectException(ValidationException::class);

        $service->resetPassword($this->user->emails[0], $token, 'Another!234');
    }

    public function test_reset_password_releases_issue_cooldown_after_password_persistence_failure(): void
    {
        $tokenService = $this->app->make(PasswordResetTokenService::class);
        $originalToken = $tokenService->issueForUser(
            userId: $this->user->id,
            email: $this->user->emails[0],
            broker: PasswordResetTokenService::LANDLORD_USERS_BROKER,
        );

        $service = new class(
            $this->app->make(\App\Application\LandlordUsers\LandlordUserAccessService::class),
            $this->app->make(PasswordResetFlowService::class),
        ) extends LandlordProfileService {
            protected function applyResetPassword(LandlordUser $user, string $password): void
            {
                throw new RuntimeException('Simulated landlord password persistence failure.');
            }
        };

        try {
            $service->resetPassword($this->user->emails[0], $originalToken, 'Another!234');
            $this->fail('Expected simulated landlord password persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated landlord password persistence failure.', $exception->getMessage());
        }

        $service->sendResetToken($this->user->emails[0]);

        $reissuedToken = $tokenService->latestIssuedTokenForTesting(
            userId: $this->user->id,
            broker: PasswordResetTokenService::LANDLORD_USERS_BROKER,
        );
        $reissuedRecord = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertIsString($reissuedToken);
        $this->assertNotSame($originalToken, $reissuedToken);
        $this->assertNotNull($reissuedRecord);
        $this->assertTrue(Hash::check($reissuedToken, (string) $reissuedRecord->token_hash));
    }

    public function test_send_reset_token_releases_user_cooldown_when_issuance_fails(): void
    {
        $email = strtolower((string) $this->user->emails[0]);

        $passwordResetFlowService = Mockery::mock(PasswordResetFlowService::class);
        $passwordResetFlowService->shouldReceive('issue')
            ->once()
            ->withArgs(function (
                string $resolvedEmail,
                string $broker,
                ?string $scope,
                mixed $resolvedUser,
                callable $userIdResolver,
            ) use ($email): bool {
                return $resolvedEmail === $email
                    && $broker === PasswordResetTokenService::LANDLORD_USERS_BROKER
                    && $scope === null
                    && $resolvedUser instanceof LandlordUser
                    && $resolvedUser->is($this->user)
                    && $userIdResolver($resolvedUser) === $this->user->id;
            })
            ->andThrow(new RuntimeException('Simulated landlord reset issuance failure.'));

        $service = new LandlordProfileService(
            $this->app->make(\App\Application\LandlordUsers\LandlordUserAccessService::class),
            $passwordResetFlowService,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated landlord reset issuance failure.');

        $service->sendResetToken($email);
    }

    public function test_send_reset_token_uses_one_cooldown_for_all_emails_of_the_same_user(): void
    {
        $secondaryEmail = $this->uniqueEmail();
        $this->user->emails = [$this->user->emails[0], $secondaryEmail];
        $this->user->save();

        $tokenService = $this->app->make(PasswordResetTokenService::class);
        $primaryEmail = strtolower((string) $this->user->emails[0]);

        $this->service->sendResetToken($primaryEmail);
        $firstToken = $tokenService->latestIssuedTokenForTesting(
            userId: $this->user->id,
            broker: PasswordResetTokenService::LANDLORD_USERS_BROKER,
        );
        $firstRecord = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertIsString($firstToken);
        $this->assertNotNull($firstRecord);
        $this->assertTrue(Hash::check($firstToken, (string) $firstRecord->token_hash));

        $this->service->sendResetToken($secondaryEmail);
        $secondToken = $tokenService->latestIssuedTokenForTesting(
            userId: $this->user->id,
            broker: PasswordResetTokenService::LANDLORD_USERS_BROKER,
        );
        $secondRecord = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', $this->user->id)
            ->first();

        $this->assertSame($firstToken, $secondToken);
        $this->assertNotNull($secondRecord);
        $this->assertSame((string) $firstRecord->token_hash, (string) $secondRecord->token_hash);
    }

    public function test_add_email(): void
    {
        $newEmail = $this->uniqueEmail();
        $updated = $this->service->addEmail($this->user, $newEmail);

        $this->assertGreaterThan(1, count($updated->emails));
        $credential = collect($updated->credentials ?? [])
            ->first(static fn (array $credential): bool => ($credential['provider'] ?? null) === 'password'
                && ($credential['subject'] ?? null) === strtolower($newEmail));
        $this->assertIsArray($credential);
        $this->assertTrue(Hash::check('Secret!234', (string) $credential['secret_hash']));
    }

    public function test_remove_email_prevents_removing_last(): void
    {
        $this->expectException(HttpResponseException::class);

        $this->service->removeEmail($this->user, $this->user->emails[0]);
    }

    public function test_remove_email_removes_password_credential_for_removed_subject(): void
    {
        $secondaryEmail = $this->uniqueEmail();
        $this->service->addEmail($this->user, $secondaryEmail);

        $updated = $this->service->removeEmail($this->user, $secondaryEmail);

        $this->assertNotContains($secondaryEmail, $updated->emails);
        $credential = collect($updated->credentials ?? [])
            ->first(static fn (array $credential): bool => ($credential['provider'] ?? null) === 'password'
                && ($credential['subject'] ?? null) === strtolower($secondaryEmail));
        $this->assertFalse(is_array($credential));
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

    private function assertPasswordStateSynchronized(LandlordUser $user, string $plainPassword): void
    {
        $freshUser = $user->fresh();

        $this->assertNotNull($freshUser);
        $this->assertNull($freshUser->getAttribute('password'));
        $this->assertNull($freshUser->getAttribute('password_type'));

        foreach ($freshUser->emails ?? [] as $email) {
            $credential = collect($freshUser->credentials ?? [])
                ->first(static fn (array $credential): bool => ($credential['provider'] ?? null) === 'password'
                    && ($credential['subject'] ?? null) === $email);

            $this->assertIsArray($credential);
            $this->assertTrue(Hash::check($plainPassword, (string) $credential['secret_hash']));
        }
    }

    private function landlordTokenCount(LandlordUser $user): int
    {
        $userId = (string) $user->getKey();

        return PersonalAccessToken::query()
            ->where(function ($query) use ($userId): void {
                $query->where('tokenable_id', $userId)
                    ->orWhere('tokenable_id', new ObjectId($userId));
            })
            ->count();
    }
}
