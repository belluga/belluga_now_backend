<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Accounts\AccountUserService;
use App\Application\Auth\TenantScopedAccessTokenService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\TenantProfileType;
use Laravel\Sanctum\NewAccessToken;
use RuntimeException;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class TenantPublicAccountTokenScopeTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplate $accountRoleTemplate;

    private AccountUser $accountUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();

        [$this->account, $this->accountRoleTemplate] = $this->seedAccountWithRole([
            'account-users:view',
        ]);

        $accountUserService = $this->app->make(AccountUserService::class);
        $this->accountUser = $accountUserService->create(
            $this->account,
            [
                'name' => 'Scoped User',
                'email' => uniqid('scoped-user-', true).'@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->accountRoleTemplate->_id
        );

        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Scoped Profile',
            'is_active' => true,
            'visibility' => 'public',
        ]);
    }

    public function test_agenda_accepts_current_tenant_account_token(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");

        $response->assertStatus(200);
    }

    public function test_account_profiles_accepts_current_tenant_account_token(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
    }

    public function test_account_route_accepts_token_bound_to_current_account_with_current_permission(): void
    {
        $operator = $this->createAccountUserWithPermissions($this->account, ['account-users:create']);
        $newToken = $this->issueScopedToken($operator, ['account-users:create'], $this->account);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->postJson("{$this->base_api_tenant}accounts/{$this->account->slug}/users", [
                'name' => 'Same Account User',
                'email' => uniqid('same-account-user-', true).'@example.org',
                'password' => 'Secret!234',
                'role_id' => (string) $this->accountRoleTemplate->_id,
            ]);

        $response->assertStatus(201);
    }

    public function test_account_route_accepts_token_resource_wildcard_bound_to_current_account(): void
    {
        $operator = $this->createAccountUserWithPermissions($this->account, ['account-users:*']);
        $newToken = $this->issueScopedToken($operator, ['account-users:*'], $this->account);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->postJson("{$this->base_api_tenant}accounts/{$this->account->slug}/users", [
                'name' => 'Wildcard Same Account User',
                'email' => uniqid('wildcard-same-account-user-', true).'@example.org',
                'password' => 'Secret!234',
                'role_id' => (string) $this->accountRoleTemplate->_id,
            ]);

        $response->assertStatus(201);
    }

    public function test_account_route_accepts_literal_wildcard_token_bound_to_current_account(): void
    {
        $operator = $this->createAccountUserWithPermissions($this->account, ['*']);
        $newToken = $this->issueScopedToken($operator, ['*'], $this->account);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->postJson("{$this->base_api_tenant}accounts/{$this->account->slug}/users", [
                'name' => 'Literal Wildcard Same Account User',
                'email' => uniqid('literal-wildcard-same-account-user-', true).'@example.org',
                'password' => 'Secret!234',
                'role_id' => (string) $this->accountRoleTemplate->_id,
            ]);

        $response->assertStatus(201);
    }

    public function test_account_events_route_accepts_events_wildcard_bearer_token_bound_to_current_account(): void
    {
        $operator = $this->createAccountUserWithPermissions($this->account, ['events:*']);
        $newToken = $this->issueScopedToken($operator, ['events:*'], $this->account);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$this->account->slug}/events");

        $response->assertStatus(200);
    }

    public function test_account_event_candidates_route_accepts_persisted_bearer_token_bound_to_current_account(): void
    {
        $operator = $this->createAccountUserWithPermissions($this->account, ['events:create']);
        $newToken = $this->issueScopedToken($operator, ['events:create'], $this->account);
        $host = AccountProfile::query()->where('account_id', (string) $this->account->_id)->firstOrFail();
        $host->display_name = 'Scoped Host';
        $host->location = [
            'type' => 'Point',
            'coordinates' => [-40.0, -20.0],
        ];
        $host->save();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$this->account->slug}/events/account_profile_candidates?type=physical_host&search=scoped%20host");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.account_id', (string) $this->account->_id);
        $response->assertJsonPath('data.0.id', (string) $host->_id);
        $response->assertJsonPath('data.0.display_name', 'Scoped Host');
    }

    public function test_account_event_candidates_route_rejects_persisted_bearer_token_bound_to_another_account(): void
    {
        [$otherAccount] = $this->seedAccountWithRole(['events:create']);
        $operator = $this->createAccountUserWithPermissions($this->account, ['events:create']);
        $operator = $this->grantUserAccountAccess($operator, $otherAccount, ['events:create']);
        $newToken = $this->issueScopedToken($operator, ['events:create'], $this->account);
        AccountProfile::create([
            'account_id' => (string) $otherAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Other Scoped Host',
            'taxonomy_terms' => [],
            'location' => [
                'type' => 'Point',
                'coordinates' => [-41.0, -21.0],
            ],
            'is_active' => true,
            'is_verified' => false,
        ]);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$otherAccount->slug}/events/account_profile_candidates?type=physical_host&search=other%20scoped%20host");

        $response->assertStatus(403);
    }

    public function test_account_event_candidates_route_rejects_persisted_bearer_token_without_candidate_ability(): void
    {
        $operator = $this->createAccountUserWithPermissions($this->account, ['events:create']);
        $newToken = $this->issueScopedToken($operator, ['events:delete'], $this->account);
        $host = AccountProfile::query()->where('account_id', (string) $this->account->_id)->firstOrFail();
        $host->display_name = 'Scoped Host Without Candidate Ability';
        $host->location = [
            'type' => 'Point',
            'coordinates' => [-40.5, -20.5],
        ];
        $host->save();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$this->account->slug}/events/account_profile_candidates?type=physical_host&search=scoped%20host%20without%20candidate%20ability");

        $response->assertStatus(403);
    }

    public function test_account_route_rejects_token_bound_to_another_account(): void
    {
        [$otherAccount] = $this->seedAccountWithRole(['account-users:view']);
        $accountUser = $this->grantUserAccountAccess($this->accountUser, $otherAccount, ['account-users:view']);
        $newToken = $this->issueScopedToken($accountUser, ['account-users:*'], $this->account);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$otherAccount->slug}/users");

        $response->assertStatus(403);
    }

    public function test_account_route_rejects_bearer_token_missing_account_binding(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser, ['account-users:view'], $this->account);
        $newToken->accessToken->setAttribute('account_id', null);
        $newToken->accessToken->save();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$this->account->slug}/users");

        $response->assertStatus(403);
    }

    public function test_direct_account_user_create_token_rejects_account_scoped_abilities_without_validated_issuer_context(): void
    {
        Account::current()?->forget();
        $tokenCount = $this->accountUser->tokens()->count();

        try {
            $this->accountUser->createToken('direct-unbound-account-token', ['account-users:view']);
            $this->fail('Direct account-scoped AccountUser token creation should fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Account-scoped AccountUser tokens must be issued through a validated account token issuer context.',
                $exception->getMessage()
            );
        }

        $this->assertSame($tokenCount, $this->accountUser->tokens()->count());
        $this->assertFalse($this->accountUser->tokens()->where('name', 'direct-unbound-account-token')->exists());
    }

    public function test_validated_issuer_context_cannot_be_opened_outside_token_service(): void
    {
        $tokenCount = $this->accountUser->tokens()->count();

        try {
            AccountUser::withValidatedAccountScopedTokenIssuerContext(
                $this->accountUser,
                (string) $this->account->_id,
                ['account-users:view'],
                fn () => $this->accountUser->createToken('forged-context-token', ['account-users:view'])
            );
            $this->fail('Only TenantScopedAccessTokenService should be able to open the validated issuer context.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Account-scoped AccountUser token issuer context may only be opened by TenantScopedAccessTokenService.',
                $exception->getMessage()
            );
        }

        $this->assertSame($tokenCount, $this->accountUser->tokens()->count());
        $this->assertFalse($this->accountUser->tokens()->where('name', 'forged-context-token')->exists());
    }

    public function test_direct_account_user_create_token_preserves_non_account_scoped_abilities(): void
    {
        Account::current()?->forget();

        $newToken = $this->accountUser->createToken('direct-tenant-token', ['tenant-push-messages:read']);

        $this->assertNotSame('', trim($newToken->plainTextToken));
        $token = $this->accountUser->tokens()->where('name', 'direct-tenant-token')->first();
        $this->assertNotNull($token);
        $this->assertSame(['tenant-push-messages:read'], (array) $token->abilities);
        $this->assertNull($token->account_id);
    }

    public function test_account_route_revalidates_current_account_permissions_before_token_abilities(): void
    {
        [$otherAccount, $otherRole] = $this->seedAccountWithRole(['account-users:view']);
        $accountUser = $this->grantUserAccountAccess($this->accountUser, $otherAccount, ['account-users:view']);
        $newToken = $this->issueScopedToken($accountUser, ['account-users:create'], $otherAccount);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->postJson("{$this->base_api_tenant}accounts/{$otherAccount->slug}/users", [
                'name' => 'Forbidden Cross Account User',
                'email' => uniqid('forbidden-cross-account-user-', true).'@example.org',
                'password' => 'Secret!234',
                'role_id' => (string) $otherRole->_id,
            ]);

        $response->assertStatus(403);
    }

    public function test_account_route_rejects_persisted_bearer_token_below_live_role_permissions(): void
    {
        $operator = $this->createAccountUserWithPermissions($this->account, ['account-users:create']);
        $newToken = $this->issueScopedToken($operator, ['account-users:view'], $this->account);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->postJson("{$this->base_api_tenant}accounts/{$this->account->slug}/users", [
                'name' => 'Token Ceiling User',
                'email' => uniqid('token-ceiling-user-', true).'@example.org',
                'password' => 'Secret!234',
                'role_id' => (string) $this->accountRoleTemplate->_id,
            ]);

        $response->assertStatus(403);
    }

    public function test_account_route_revalidates_role_downgrade_on_next_request(): void
    {
        $operator = $this->createAccountUserWithPermissions($this->account, ['account-users:*']);
        $newToken = $this->issueScopedToken($operator, ['account-users:*'], $this->account);

        $allowed = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->postJson("{$this->base_api_tenant}accounts/{$this->account->slug}/users", [
                'name' => 'Pre Downgrade User',
                'email' => uniqid('pre-downgrade-user-', true).'@example.org',
                'password' => 'Secret!234',
                'role_id' => (string) $this->accountRoleTemplate->_id,
            ]);
        $allowed->assertStatus(201);

        $role = $operator->accountRoles()
            ->where('account_id', $this->account->id)
            ->firstOrFail();
        $role->permissions = ['account-users:view'];
        $role->save();

        $this->app['auth']->forgetGuards();

        $denied = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->postJson("{$this->base_api_tenant}accounts/{$this->account->slug}/users", [
                'name' => 'Post Downgrade User',
                'email' => uniqid('post-downgrade-user-', true).'@example.org',
                'password' => 'Secret!234',
                'role_id' => (string) $this->accountRoleTemplate->_id,
            ]);

        $denied->assertStatus(403);
    }

    public function test_account_route_revalidates_membership_removal_on_next_request(): void
    {
        $operator = $this->createAccountUserWithPermissions($this->account, ['account-users:view']);
        $newToken = $this->issueScopedToken($operator, ['account-users:view'], $this->account);

        $allowed = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$this->account->slug}/users");
        $allowed->assertStatus(200);

        $this->app->make(AccountUserService::class)->remove($this->account, $operator);
        $this->app['auth']->forgetGuards();

        $denied = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$this->account->slug}/users");

        $denied->assertStatus(401);
    }

    public function test_no_current_account_issuance_after_stale_account_context_binds_only_single_accessible_account(): void
    {
        $staleAccount = $this->account;
        [$targetAccount] = $this->seedAccountWithRole(['account-users:view']);
        $operator = $this->createAccountUserWithPermissions($targetAccount, ['account-users:view']);

        $staleAccount->makeCurrent();
        $this->assertFalse($operator->fresh()->haveAccessTo($staleAccount));
        Account::current()?->forget();

        $newToken = $this->issueScopedToken($operator, ['account-users:view']);
        $this->assertSame((string) $targetAccount->_id, (string) $newToken->accessToken->account_id);

        $wrongAccount = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$staleAccount->slug}/users");
        $wrongAccount->assertStatus(401);

        $this->app['auth']->forgetGuards();

        $target = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}accounts/{$targetAccount->slug}/users");
        $target->assertStatus(200);
    }

    public function test_agenda_rejects_account_token_with_foreign_tenant_scope(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);
        $newToken->accessToken->setAttribute('tenant_id', 'foreign-tenant-id');
        $newToken->accessToken->save();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");

        $response->assertStatus(403);
    }

    public function test_account_profiles_rejects_account_token_with_foreign_tenant_scope(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);
        $newToken->accessToken->setAttribute('tenant_id', 'foreign-tenant-id');
        $newToken->accessToken->save();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(403);
    }

    public function test_account_profiles_first_page_accepts_anonymous_tenant_token(): void
    {
        $token = $this->issueAnonymousIdentityToken();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame('venue', $data[0]['profile_type'] ?? null);
    }

    public function test_agenda_accepts_anonymous_tenant_token(): void
    {
        $token = $this->issueAnonymousIdentityToken();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("{$this->base_api_tenant}agenda?page=1&page_size=10");

        $response->assertStatus(200);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Scoped', 'subdomain' => 'tenant-scoped'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-scoped.test']
        );

        $service->initialize($payload);
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function issueScopedToken(
        AccountUser $user,
        array $abilities = ['account-users:view'],
        ?Account $account = null
    ): NewAccessToken {
        $tokenService = $this->app->make(TenantScopedAccessTokenService::class);

        return $tokenService->issueForAccountUser(
            $user,
            'scoped-test-token',
            $abilities,
            accountId: $account ? (string) $account->_id : null
        );
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAccountUserWithPermissions(Account $account, array $permissions): AccountUser
    {
        $role = $account->roleTemplates()->create([
            'name' => 'Scoped Operator '.uniqid(),
            'description' => 'Scoped operator fixture',
            'permissions' => $permissions,
        ]);

        return $this->app->make(AccountUserService::class)->create(
            $account,
            [
                'name' => 'Scoped Operator',
                'email' => uniqid('scoped-operator-', true).'@example.org',
                'password' => 'Secret!234',
            ],
            (string) $role->_id
        );
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function grantUserAccountAccess(AccountUser $user, Account $account, array $permissions): AccountUser
    {
        $role = $account->roleTemplates()->create([
            'name' => 'Scoped Grant '.uniqid(),
            'description' => 'Scoped grant fixture',
            'permissions' => $permissions,
        ]);

        return $this->app->make(AccountUserService::class)->create(
            $account,
            [
                'name' => (string) $user->name,
                'email' => (string) ($user->emails[0] ?? uniqid('scoped-grant-', true).'@example.org'),
                'password' => 'Secret!234',
            ],
            (string) $role->_id
        );
    }

    private function issueAnonymousIdentityToken(): string
    {
        $response = $this->postJson("{$this->base_api_tenant}anonymous/identities", [
            'device_name' => 'tenant-public-discovery-test-device',
            'fingerprint' => [
                'hash' => hash('sha256', 'tenant-public-discovery-test-device'),
                'user_agent' => 'TenantPublicAccountTokenScopeTest/1.0',
                'locale' => 'pt-BR',
            ],
            'metadata' => [
                'source' => 'feature-test',
            ],
        ]);

        $response->assertStatus(201);

        $token = (string) $response->json('data.token');
        $this->assertNotSame('', trim($token));

        return $token;
    }
}
