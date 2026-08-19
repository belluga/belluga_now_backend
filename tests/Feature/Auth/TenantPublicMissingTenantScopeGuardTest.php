<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Accounts\AccountUserService;
use App\Application\Auth\TenantScopedAccessTokenService;
use App\Models\Landlord\PersonalAccessToken;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Laravel\Sanctum\NewAccessToken;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class TenantPublicMissingTenantScopeGuardTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private Account $account;

    private AccountRoleTemplate $accountRoleTemplate;

    private AccountUser $accountUser;

    protected function prepareAuthenticatedHarnessState(): void
    {
        $this->refreshLandlordAndTenantDatabases();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);

        [$this->account, $this->accountRoleTemplate] = $this->seedAccountWithRole(['account-users:view']);
        $this->accountUser = $this->app->make(AccountUserService::class)->create(
            $this->account,
            [
                'name' => 'Scoped User',
                'email' => uniqid('scoped-user-', true).'@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->accountRoleTemplate->_id
        );
    }

    public function test_me_accepts_current_tenant_account_token(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}me");

        $response->assertStatus(200);
    }

    public function test_me_rejects_account_token_with_foreign_tenant_scope(): void
    {
        $newToken = $this->issueScopedToken($this->accountUser);
        $newToken->accessToken->setAttribute('tenant_id', 'foreign-tenant-id');
        $newToken->accessToken->save();

        $response = $this
            ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
            ->getJson("{$this->base_api_tenant}me");

        $response->assertStatus(403);
    }

    public function test_me_rejects_account_token_with_blank_or_missing_tenant_scope(): void
    {
        foreach ($this->blankTenantScopeMutators() as $label => $mutate) {
            $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
            $newToken = $this->issueScopedToken($this->accountUser);
            $mutate($newToken->accessToken);
            $newToken->accessToken->save();

            $response = $this
                ->withHeaders(['Authorization' => "Bearer {$newToken->plainTextToken}"])
                ->getJson("{$this->base_api_tenant}me");

            $response->assertStatus(403, $label);
        }
    }

    private function issueScopedToken(AccountUser $user): NewAccessToken
    {
        $tokenService = $this->app->make(TenantScopedAccessTokenService::class);

        return $tokenService->issueForAccountUser(
            $user,
            'missing-tenant-scope-guard-test-token',
            ['*'],
            accountId: (string) $this->account->_id
        );
    }

    /**
     * @return array<string, callable(PersonalAccessToken): void>
     */
    private function blankTenantScopeMutators(): array
    {
        return [
            'null' => fn (PersonalAccessToken $token) => $token->setAttribute('tenant_id', null),
            'empty_string' => fn (PersonalAccessToken $token) => $token->setAttribute('tenant_id', ''),
            'whitespace_only' => fn (PersonalAccessToken $token) => $token->setAttribute('tenant_id', '   '),
            'missing_attribute' => fn (PersonalAccessToken $token) => $token->offsetUnset('tenant_id'),
        ];
    }
}
