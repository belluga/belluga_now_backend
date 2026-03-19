<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\Accounts\AccountManagementService;
use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplate $role;

    private AccountManagementService $accountService;

    private AccountUserService $userService;

    private string $tenantAccountsAdminUrl;

    private string $tenantAccountOnboardingsAdminUrl;

    private string $baseUrl;

    private string $baseAdminUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account, $this->role] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
        $this->account->makeCurrent();

        $this->accountService = $this->app->make(AccountManagementService::class);
        $this->userService = $this->app->make(AccountUserService::class);

        $landlord = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlord, [
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        $tenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();
        $tenantHost = "{$tenant->subdomain}.{$this->host}";
        $this->tenantAccountsAdminUrl = "http://{$tenantHost}/admin/api/v1/accounts";
        $this->tenantAccountOnboardingsAdminUrl = "http://{$tenantHost}/admin/api/v1/account_onboardings";
        $this->baseUrl = "http://{$tenantHost}/api/v1/accounts/{$this->account->slug}";
        $this->baseAdminUrl = "http://{$tenantHost}/admin/api/v1/accounts/{$this->account->slug}";
    }

    public function test_store_creates_account(): void
    {
        $name = fake()->unique()->company();
        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account.ownership_state', 'tenant_owned');

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function test_store_allows_duplicate_document_across_accounts(): void
    {
        $firstName = fake()->unique()->company();
        $secondName = fake()->unique()->company();
        $firstResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $firstName,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $firstResponse->assertCreated();
        $firstResponse->assertJsonPath('data.account.name', $firstName);

        $secondResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $secondName,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $secondResponse->assertCreated();
        $secondResponse->assertJsonPath('data.account.name', $secondName);

        Account::where('name', $firstName)->first()?->forceDelete();
        Account::where('name', $secondName)->first()?->forceDelete();
    }

    public function test_store_creates_unmanaged_account_without_organization(): void
    {
        $name = fake()->unique()->company();
        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'unmanaged',
            'profile_type' => 'personal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account.ownership_state', 'unmanaged');
        $this->assertNull($response->json('data.account.organization_id'));

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function test_store_creates_tenant_owned_account_without_tenant_organization_context(): void
    {
        $tenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();
        $tenant->organization_id = null;
        $tenant->save();

        $name = fake()->unique()->company();
        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account.ownership_state', 'tenant_owned');

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function test_unmanaged_account_with_operator_is_returned_as_user_owned(): void
    {
        $name = fake()->unique()->company();
        $createResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'unmanaged',
            'profile_type' => 'personal',
        ]);

        $createResponse->assertCreated();
        $accountSlug = $createResponse->json('data.account.slug');
        $account = Account::query()->where('slug', $accountSlug)->firstOrFail();
        $role = $account->roleTemplates()->firstOrFail();

        $account->makeCurrent();
        $this->userService->create(
            $account,
            [
                'name' => 'Managed User',
                'email' => fake()->unique()->safeEmail(),
                'password' => 'Secret!234',
            ],
            (string) $role->_id
        );

        $showResponse = $this->getJson("{$this->tenantAccountsAdminUrl}/{$accountSlug}");

        $showResponse->assertOk();
        $showResponse->assertJsonPath('data.ownership_state', 'user_owned');
    }

    public function test_store_allows_missing_document(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create']);

        $name = fake()->unique()->company();
        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function test_store_rejects_invalid_ownership_state(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create']);

        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => 'Account Invalid Ownership',
            'ownership_state' => 'user_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertStatus(422);
    }

    public function test_store_forbidden_without_create_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => 'Account Forbidden',
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertStatus(403);
    }

    public function test_index_filters_by_current_user(): void
    {
        $response = $this->getJson($this->tenantAccountsAdminUrl);

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains((string) $this->account->_id));
        $this->assertTrue(
            collect($response->json('data'))->every(
                static fn (array $item): bool => array_key_exists('ownership_state', $item)
            )
        );
    }

    public function test_index_filters_by_unmanaged_ownership_state(): void
    {
        $unmanagedName = fake()->unique()->company();
        $tenantOwnedName = fake()->unique()->company();
        $userOwnedName = fake()->unique()->company();

        $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $unmanagedName,
            'ownership_state' => 'unmanaged',
            'profile_type' => 'personal',
        ])->assertCreated();

        $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $tenantOwnedName,
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ])->assertCreated();

        $userOwnedCreateResponse = $this->postJson($this->tenantAccountOnboardingsAdminUrl, [
            'name' => $userOwnedName,
            'ownership_state' => 'unmanaged',
            'profile_type' => 'personal',
        ]);
        $userOwnedCreateResponse->assertCreated();

        $userOwnedSlug = $userOwnedCreateResponse->json('data.account.slug');
        $userOwnedAccount = Account::query()->where('slug', $userOwnedSlug)->firstOrFail();
        $userOwnedRole = $userOwnedAccount->roleTemplates()->firstOrFail();

        $userOwnedAccount->makeCurrent();
        $this->userService->create(
            $userOwnedAccount,
            [
                'name' => 'Managed User',
                'email' => fake()->unique()->safeEmail(),
                'password' => 'Secret!234',
            ],
            (string) $userOwnedRole->_id
        );

        $response = $this->getJson(
            "{$this->tenantAccountsAdminUrl}?ownership_state=unmanaged"
        );

        $response->assertOk();
        $items = collect($response->json('data'));
        $this->assertGreaterThanOrEqual(1, $items->count());
        $this->assertTrue(
            $items->contains(
                static fn (array $item): bool => ($item['name'] ?? null) === $unmanagedName
            )
        );
        $this->assertFalse(
            $items->contains(
                static fn (array $item): bool => ($item['name'] ?? null) === $tenantOwnedName
            )
        );
        $this->assertFalse(
            $items->contains(
                static fn (array $item): bool => ($item['name'] ?? null) === $userOwnedName
            )
        );
        $this->assertTrue(
            $items->every(
                static fn (array $item): bool => ($item['ownership_state'] ?? null) === 'unmanaged'
            )
        );
    }

    public function test_index_forbidden_without_view_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create']);

        $response = $this->getJson($this->tenantAccountsAdminUrl);

        $response->assertStatus(403);
    }

    public function test_update_forbidden_without_update_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->patchJson($this->baseAdminUrl, [
            'name' => 'Forbidden Update',
        ]);

        $response->assertStatus(403);
    }

    public function test_delete_forbidden_without_delete_ability(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->deleteJson($this->baseAdminUrl);

        $response->assertStatus(403);
    }

    public function test_account_user_manage_attaches_and_detaches(): void
    {
        $user = $this->userService->create($this->account, [
            'name' => 'Member',
            'email' => 'member@example.org',
            'password' => 'Secret!234',
        ], (string) $this->role->_id);

        $role = $this->account->roleTemplates()->create([
            'name' => 'Viewer',
            'permissions' => ['account-users:view'],
        ]);

        $attachResponse = $this->postJson(
            sprintf('%s/users/%s/roles/%s', $this->baseAdminUrl, $user->_id, $role->_id)
        );

        $attachResponse->assertOk();

        $detachResponse = $this->deleteJson(
            sprintf('%s/users/%s/roles/%s', $this->baseAdminUrl, $user->_id, $role->_id)
        );

        $detachResponse->assertOk();
    }

    public function test_legacy_accounts_create_route_returns_policy_rejection(): void
    {
        $response = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => fake()->company(),
            'ownership_state' => 'tenant_owned',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'tenant_admin_onboarding_required');
        $response->assertJsonPath('meta.use_endpoint', '/admin/api/v1/account_onboardings');
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);
    }
}
