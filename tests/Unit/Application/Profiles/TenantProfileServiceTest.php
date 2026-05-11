<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Profiles;

use App\Application\AccountProfiles\AccountProfileBootstrapService;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMediaService;
use App\Application\Auth\PasswordResetFlowService;
use App\Application\Auth\PasswordResetTokenService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Profiles\TenantProfileService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_update_profile_updates_attributes(): void
    {
        $updated = $this->service->updateProfile(
            $this->user,
            ['name' => 'Updated Name'],
            Request::create('/api/v1/profile', 'PATCH'),
        );

        $this->assertSame('Updated Name', $updated->name);
    }

    public function test_update_profile_rejects_empty_payload(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->updateProfile(
            $this->user,
            [],
            Request::create('/api/v1/profile', 'PATCH'),
        );
    }

    public function test_update_profile_persists_personal_profile_fields(): void
    {
        $updated = $this->service->updateProfile(
            $this->user,
            [
                'name' => 'Perfil Persistido',
                'bio' => 'Bio persistida no personal profile.',
            ],
            Request::create('/api/v1/profile', 'PATCH'),
        );

        $this->assertSame('Perfil Persistido', $updated->name);

        $profile = AccountProfile::query()
            ->where('created_by', (string) $this->user->_id)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->first();

        $this->assertInstanceOf(AccountProfile::class, $profile);
        $this->assertSame('Perfil Persistido', $profile->display_name);
        $this->assertSame(
            'Bio persistida no personal profile.',
            trim(strip_tags((string) $profile->bio)),
        );
    }

    public function test_update_password_hashes_secret(): void
    {
        $this->service->updatePassword($this->user, 'Another!234');

        $this->assertTrue(Hash::check('Another!234', (string) $this->user->fresh()->password));
    }

    public function test_add_email_appends_new_address(): void
    {
        $newEmail = $this->uniqueEmail();

        $updated = $this->service->addEmail($this->user, $newEmail);

        $this->assertContains($newEmail, $updated->emails);
    }

    public function test_remove_email_prevents_removing_last(): void
    {
        $this->expectException(HttpResponseException::class);

        $this->service->removeEmail($this->user, $this->user->emails[0]);
    }

    public function test_add_phones_stores_parsed_numbers(): void
    {
        $updated = $this->service->addPhones($this->user, [$this->uniquePhoneNumber()]);

        $this->assertNotEmpty($updated->phones);
    }

    public function test_add_phones_rejects_invalid(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->addPhones($this->user, ['invalid-phone']);
    }

    public function test_send_reset_token_passes_current_tenant_scope_to_password_reset_service(): void
    {
        $email = strtolower((string) $this->user->emails[0]);
        $tenantScope = (string) Tenant::current()?->getKey();

        $passwordResetFlowService = Mockery::mock(PasswordResetFlowService::class);
        $passwordResetFlowService->shouldReceive('issue')
            ->once()
            ->withArgs(function (
                string $resolvedEmail,
                string $broker,
                ?string $scope,
                mixed $resolvedUser,
                callable $userIdResolver,
            ) use ($email, $tenantScope): bool {
                return $resolvedEmail === $email
                    && $broker === PasswordResetTokenService::TENANT_USERS_BROKER
                    && $scope === $tenantScope
                    && $resolvedUser instanceof AccountUser
                    && $resolvedUser->is($this->user)
                    && $userIdResolver($resolvedUser) === $this->user->id;
            });

        $service = new TenantProfileService(
            Mockery::mock(AccountProfileBootstrapService::class),
            Mockery::mock(AccountProfileManagementService::class),
            Mockery::mock(AccountProfileMediaService::class),
            $passwordResetFlowService,
        );

        $service->sendResetToken($email);

        $this->assertSame($tenantScope, (string) Tenant::current()?->getKey());
    }

    public function test_send_reset_token_releases_user_cooldown_when_issuance_fails(): void
    {
        $email = strtolower((string) $this->user->emails[0]);
        $tenantScope = (string) Tenant::current()?->getKey();

        $passwordResetFlowService = Mockery::mock(PasswordResetFlowService::class);
        $passwordResetFlowService->shouldReceive('issue')
            ->once()
            ->withArgs(function (
                string $resolvedEmail,
                string $broker,
                ?string $scope,
                mixed $resolvedUser,
                callable $userIdResolver,
            ) use ($email, $tenantScope): bool {
                return $resolvedEmail === $email
                    && $broker === PasswordResetTokenService::TENANT_USERS_BROKER
                    && $scope === $tenantScope
                    && $resolvedUser instanceof AccountUser
                    && $resolvedUser->is($this->user)
                    && $userIdResolver($resolvedUser) === $this->user->id;
            })
            ->andThrow(new RuntimeException('Simulated tenant reset issuance failure.'));

        $service = new TenantProfileService(
            Mockery::mock(AccountProfileBootstrapService::class),
            Mockery::mock(AccountProfileManagementService::class),
            Mockery::mock(AccountProfileMediaService::class),
            $passwordResetFlowService,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated tenant reset issuance failure.');

        $service->sendResetToken($email);
    }

    public function test_send_reset_token_uses_one_cooldown_for_all_emails_of_the_same_user(): void
    {
        $secondaryEmail = $this->uniqueEmail();
        $this->user->emails = [$this->user->emails[0], $secondaryEmail];
        $this->user->save();

        $tokenService = $this->app->make(PasswordResetTokenService::class);
        $primaryEmail = strtolower((string) $this->user->emails[0]);
        $tenantScope = (string) Tenant::current()?->getKey();

        $this->service->sendResetToken($primaryEmail);
        $firstToken = $tokenService->latestIssuedTokenForTesting(
            userId: $this->user->id,
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: $tenantScope,
        );
        $firstRecord = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', $this->user->id)
            ->where('scope_key', $tenantScope)
            ->first();

        $this->assertIsString($firstToken);
        $this->assertNotNull($firstRecord);
        $this->assertTrue(Hash::check($firstToken, (string) $firstRecord->token_hash));

        $this->service->sendResetToken($secondaryEmail);
        $secondToken = $tokenService->latestIssuedTokenForTesting(
            userId: $this->user->id,
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: $tenantScope,
        );
        $secondRecord = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', $this->user->id)
            ->where('scope_key', $tenantScope)
            ->first();

        $this->assertSame($firstToken, $secondToken);
        $this->assertNotNull($secondRecord);
        $this->assertSame((string) $firstRecord->token_hash, (string) $secondRecord->token_hash);
    }

    public function test_reset_password_burns_the_token_before_password_persistence_failure(): void
    {
        $tokenService = $this->app->make(PasswordResetTokenService::class);
        $token = $tokenService->issueForUser(
            userId: $this->user->id,
            email: (string) $this->user->emails[0],
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: (string) Tenant::current()?->getKey(),
        );

        $service = new class(
            $this->app->make(AccountProfileBootstrapService::class),
            $this->app->make(AccountProfileManagementService::class),
            $this->app->make(AccountProfileMediaService::class),
            $this->app->make(PasswordResetFlowService::class),
        ) extends TenantProfileService {
            protected function applyResetPassword(AccountUser $user, string $password): void
            {
                throw new RuntimeException('Simulated tenant password persistence failure.');
            }
        };

        try {
            $service->resetPassword((string) $this->user->emails[0], $token, 'Another!234');
            $this->fail('Expected simulated tenant password persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated tenant password persistence failure.', $exception->getMessage());
        }

        $this->assertNull(
            DB::connection('landlord')
                ->table('password_reset_tokens')
                ->where('user_id', $this->user->id)
                ->first()
        );

        $this->expectException(ValidationException::class);

        $service->resetPassword((string) $this->user->emails[0], $token, 'Another!234');
    }

    public function test_reset_password_releases_issue_cooldown_after_password_persistence_failure(): void
    {
        $tokenService = $this->app->make(PasswordResetTokenService::class);
        $tenantScope = (string) Tenant::current()?->getKey();
        $originalToken = $tokenService->issueForUser(
            userId: $this->user->id,
            email: (string) $this->user->emails[0],
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: $tenantScope,
        );

        $service = new class(
            $this->app->make(AccountProfileBootstrapService::class),
            $this->app->make(AccountProfileManagementService::class),
            $this->app->make(AccountProfileMediaService::class),
            $this->app->make(PasswordResetFlowService::class),
        ) extends TenantProfileService {
            protected function applyResetPassword(AccountUser $user, string $password): void
            {
                throw new RuntimeException('Simulated tenant password persistence failure.');
            }
        };

        try {
            $service->resetPassword((string) $this->user->emails[0], $originalToken, 'Another!234');
            $this->fail('Expected simulated tenant password persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated tenant password persistence failure.', $exception->getMessage());
        }

        $service->sendResetToken((string) $this->user->emails[0]);

        $reissuedToken = $tokenService->latestIssuedTokenForTesting(
            userId: $this->user->id,
            broker: PasswordResetTokenService::TENANT_USERS_BROKER,
            scope: $tenantScope,
        );
        $reissuedRecord = DB::connection('landlord')
            ->table('password_reset_tokens')
            ->where('user_id', $this->user->id)
            ->where('scope_key', $tenantScope)
            ->first();

        $this->assertIsString($reissuedToken);
        $this->assertNotSame($originalToken, $reissuedToken);
        $this->assertNotNull($reissuedRecord);
        $this->assertTrue(Hash::check($reissuedToken, (string) $reissuedRecord->token_hash));
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
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
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
        return '+55'.random_int(1000000000, 1999999999);
    }

    private function uniqueEmail(): string
    {
        return sprintf('user-%s@example.org', Str::uuid());
    }
}
