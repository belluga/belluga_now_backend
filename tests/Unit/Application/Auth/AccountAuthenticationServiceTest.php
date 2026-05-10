<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Auth;

use App\Application\Accounts\AccountUserAccessService;
use App\Application\Accounts\AccountUserService;
use App\Application\Auth\AccountAuthenticationService;
use App\Application\Auth\TenantScopedAccessTokenService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Belluga\Settings\Models\Landlord\LandlordSettings;
use Belluga\Settings\Models\Tenants\TenantSettings;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

#[Group('atlas-critical')]
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
        $this->user->accountRoles()->create([
            ...$role->attributesToArray(),
            'account_id' => $account->id,
        ]);
        $this->user = $this->user->fresh();
    }

    public function test_login_returns_token(): void
    {
        $result = $this->service->login($this->user->emails[0], 'Secret!234', 'api-client');

        $this->assertSame($this->user->emails[0], $result->user->emails[0]);
        $this->assertNotEmpty($result->plainTextToken);
        $this->assertSame(
            (string) Account::current()?->_id,
            (string) $result->user->tokens()->where('name', 'api-client')->first()?->account_id
        );
    }

    public function test_login_without_current_account_uses_exactly_one_accessible_account(): void
    {
        Account::current()?->forget();

        $result = $this->service->login($this->user->emails[0], 'Secret!234', 'single-account-client');
        $token = $result->user->tokens()->where('name', 'single-account-client')->first();

        $this->assertNotNull($token);
        $this->assertSame($this->user->getAccessToIds()[0], (string) $token->account_id);
    }

    public function test_login_without_current_account_fails_closed_for_multiple_accessible_accounts(): void
    {
        [$otherAccount, $otherRole] = $this->seedAccountWithRole(['account-users:view']);
        $this->app->make(AccountUserService::class)->create(
            $otherAccount,
            [
                'name' => (string) $this->user->name,
                'email' => (string) $this->user->emails[0],
                'password' => 'Secret!234',
            ],
            (string) $otherRole->_id
        );
        Account::current()?->forget();

        $this->expectException(InvalidCredentialsException::class);

        $this->service->login($this->user->emails[0], 'Secret!234', 'multi-account-client');
    }

    public function test_login_without_current_account_fails_closed_when_user_has_no_account_access(): void
    {
        $user = AccountUser::create([
            'name' => 'No Access User',
            'emails' => [$this->uniqueEmail()],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
        ]);
        Account::current()?->forget();

        $this->expectException(InvalidCredentialsException::class);

        $this->service->login((string) $user->emails[0], 'Secret!234', 'no-account-client');
    }

    public function test_password_login_remains_available_when_tenant_public_auth_is_pinned_to_phone_otp(): void
    {
        $landlord = LandlordSettings::current();
        if ($landlord === null) {
            $landlord = new LandlordSettings;
            $landlord->setAttribute('_id', 'settings_root');
        }
        $landlord->setAttribute('tenant_public_auth', [
            'available_methods' => ['password', 'phone_otp'],
            'allow_tenant_customization' => true,
        ]);
        $landlord->save();

        $tenantSettings = TenantSettings::current();
        if ($tenantSettings === null) {
            $tenantSettings = new TenantSettings;
            $tenantSettings->setAttribute('_id', 'settings_root');
        }
        $tenantSettings->setAttribute('tenant_public_auth', [
            'enabled_methods' => ['phone_otp'],
        ]);
        $tenantSettings->save();

        $result = $this->service->login($this->user->emails[0], 'Secret!234', 'api-client');

        $this->assertSame($this->user->emails[0], $result->user->emails[0]);
        $this->assertNotEmpty($result->plainTextToken);
        $this->assertTrue($this->user->fresh()?->password !== null);
    }

    public function test_login_throws_when_credentials_invalid(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        $this->service->login($this->user->emails[0], 'wrong-password', 'api-client');
    }

    public function test_logout_deletes_device_tokens(): void
    {
        $result = $this->service->login($this->user->emails[0], 'Secret!234', 'api-client');

        $this->service->logout($result->user, false, 'api-client');

        $this->assertCount(0, $result->user->tokens);
    }

    public function test_login_issued_literal_wildcard_token_allows_account_workspace_catalog_abilities(): void
    {
        $account = Account::current();
        $this->assertNotNull($account);

        $role = $account->roleTemplates()->create([
            'name' => 'Literal Wildcard Access',
            'description' => 'Literal wildcard fixture',
            'permissions' => ['*'],
        ]);
        $user = $this->app->make(AccountUserService::class)->create(
            $account,
            [
                'name' => 'Literal Wildcard User',
                'email' => $this->uniqueEmail(),
                'password' => 'Secret!234',
            ],
            (string) $role->_id
        );

        $result = $this->service->login((string) $user->emails[0], 'Secret!234', 'wildcard-client');
        $token = $result->user->tokens()->where('name', 'wildcard-client')->first();
        $this->assertNotNull($token);

        $abilities = (array) $token->abilities;
        $accessService = $this->app->make(AccountUserAccessService::class);

        $this->assertContains('*', $abilities);
        $this->assertTrue($accessService->abilityListAllows($abilities, 'account-users:view'));
        $this->assertTrue($accessService->abilityListAllows($abilities, 'account-roles:view'));
        $this->assertTrue($accessService->abilityListAllows($abilities, 'events:read'));
        $this->assertTrue($accessService->abilityListAllows($abilities, 'push-messages:read'));
    }

    #[DataProvider('accountScopedAbilityProvider')]
    public function test_account_scoped_abilities_are_not_issued_without_account_context(string $ability): void
    {
        $currentAccount = Account::current();
        $this->assertNotNull($currentAccount);

        Account::current()?->forget();

        [$otherAccount, $otherRole] = $this->seedAccountWithRole([$ability]);
        $this->app->make(AccountUserService::class)->create(
            $otherAccount,
            [
                'name' => (string) $this->user->name,
                'email' => (string) $this->user->emails[0],
                'password' => 'Secret!234',
            ],
            (string) $otherRole->_id
        );
        Account::current()?->forget();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot issue account-scoped account token without account context.');

        $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $this->user->fresh(),
            'missing-account-context',
            [$ability],
            (string) Tenant::current()?->_id
        );
    }

    public function test_account_scoped_abilities_are_not_issued_for_explicit_inaccessible_account(): void
    {
        [$otherAccount] = $this->seedAccountWithRole(['account-users:view']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot issue account-scoped account token for inaccessible account context.');

        $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $this->user->fresh(),
            'explicit-foreign-account',
            ['account-users:view'],
            (string) Tenant::current()?->_id,
            (string) $otherAccount->_id
        );
    }

    public function test_account_scoped_abilities_are_not_issued_for_stale_current_inaccessible_account(): void
    {
        [$otherAccount] = $this->seedAccountWithRole(['account-users:view']);
        $otherAccount->makeCurrent();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot issue account-scoped account token for inaccessible account context.');

        $this->app->make(TenantScopedAccessTokenService::class)->issueForAccountUser(
            $this->user->fresh(),
            'stale-current-account',
            ['account-users:view'],
            (string) Tenant::current()?->_id
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function accountScopedAbilityProvider(): array
    {
        return [
            'account-users:view' => ['account-users:view'],
            'account-users wildcard' => ['account-users:*'],
            'events wildcard' => ['events:*'],
            'push-messages wildcard' => ['push-messages:*'],
            'literal wildcard' => ['*'],
        ];
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
