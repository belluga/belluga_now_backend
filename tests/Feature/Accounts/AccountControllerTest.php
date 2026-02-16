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
        $this->baseUrl = "http://{$tenantHost}/api/v1/accounts/{$this->account->slug}";
        $this->baseAdminUrl = "http://{$tenantHost}/admin/api/v1/accounts/{$this->account->slug}";
    }

    public function testStoreCreatesAccount(): void
    {
        $name = fake()->unique()->company();
        $documentNumber = fake()->unique()->numerify('###################');

        $response = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => $name,
            'document' => [
                'type' => 'cpf',
                'number' => $documentNumber,
            ],
            'ownership_state' => 'tenant_owned',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account.ownership_state', 'tenant_owned');

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function testStoreAllowsDuplicateDocumentAcrossAccounts(): void
    {
        $firstName = fake()->unique()->company();
        $secondName = fake()->unique()->company();
        $documentNumber = fake()->unique()->numerify('###################');

        $firstResponse = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => $firstName,
            'document' => [
                'type' => 'cpf',
                'number' => $documentNumber,
            ],
            'ownership_state' => 'tenant_owned',
        ]);

        $firstResponse->assertCreated();
        $firstResponse->assertJsonPath('data.account.name', $firstName);

        $secondResponse = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => $secondName,
            'document' => [
                'type' => 'cpf',
                'number' => $documentNumber,
            ],
            'ownership_state' => 'tenant_owned',
        ]);

        $secondResponse->assertCreated();
        $secondResponse->assertJsonPath('data.account.name', $secondName);

        Account::where('name', $firstName)->first()?->forceDelete();
        Account::where('name', $secondName)->first()?->forceDelete();
    }

    public function testStoreCreatesUnmanagedAccountWithoutOrganization(): void
    {
        $name = fake()->unique()->company();
        $documentNumber = fake()->unique()->numerify('###################');

        $response = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => $name,
            'document' => [
                'type' => 'cpf',
                'number' => $documentNumber,
            ],
            'ownership_state' => 'unmanaged',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account.ownership_state', 'unmanaged');
        $this->assertNull($response->json('data.account.organization_id'));

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function testStoreCreatesTenantOwnedAccountWithoutTenantOrganizationContext(): void
    {
        $tenant = Tenant::query()->where('subdomain', 'tenant-zeta')->firstOrFail();
        $tenant->organization_id = null;
        $tenant->save();

        $name = fake()->unique()->company();
        $documentNumber = fake()->unique()->numerify('###################');

        $response = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => $name,
            'document' => [
                'type' => 'cpf',
                'number' => $documentNumber,
            ],
            'ownership_state' => 'tenant_owned',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);
        $response->assertJsonPath('data.account.ownership_state', 'tenant_owned');

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function testUnmanagedAccountWithOperatorIsReturnedAsUserOwned(): void
    {
        $name = fake()->unique()->company();
        $documentNumber = fake()->unique()->numerify('###################');

        $createResponse = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => $name,
            'document' => [
                'type' => 'cpf',
                'number' => $documentNumber,
            ],
            'ownership_state' => 'unmanaged',
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

    public function testStoreAllowsMissingDocument(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create']);

        $name = fake()->unique()->company();
        $response = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => $name,
            'ownership_state' => 'tenant_owned',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function testStoreRejectsInvalidOwnershipState(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create']);

        $response = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => 'Account Invalid Ownership',
            'document' => [
                'type' => 'cpf',
                'number' => fake()->unique()->numerify('###################'),
            ],
            'ownership_state' => 'user_owned',
        ]);

        $response->assertStatus(422);
    }

    public function testStoreForbiddenWithoutCreateAbility(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->postJson($this->tenantAccountsAdminUrl, [
            'name' => 'Account Forbidden',
            'document' => [
                'type' => 'cpf',
                'number' => fake()->unique()->numerify('###################'),
            ],
            'ownership_state' => 'tenant_owned',
        ]);

        $response->assertStatus(403);
    }

    public function testIndexFiltersByCurrentUser(): void
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

    public function testIndexForbiddenWithoutViewAbility(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:create']);

        $response = $this->getJson($this->tenantAccountsAdminUrl);

        $response->assertStatus(403);
    }

    public function testUpdateForbiddenWithoutUpdateAbility(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->patchJson($this->baseAdminUrl, [
            'name' => 'Forbidden Update',
        ]);

        $response->assertStatus(403);
    }

    public function testDeleteForbiddenWithoutDeleteAbility(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->deleteJson($this->baseAdminUrl);

        $response->assertStatus(403);
    }

    public function testAccountUserManageAttachesAndDetaches(): void
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
