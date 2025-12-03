<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\Accounts\AccountManagementService;
use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private AccountUser $operator;

    private Account $account;

    private AccountRoleTemplate $role;

    private AccountManagementService $accountService;

    private AccountUserService $userService;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        [$this->account, $this->role] = $this->seedAccountWithRole(['account-users:*']);
        $this->account->makeCurrent();

        $this->accountService = $this->app->make(AccountManagementService::class);
        $this->userService = $this->app->make(AccountUserService::class);

        $operatorRole = $this->account->roleTemplates()->create([
            'name' => 'Operator',
            'permissions' => ['account-users:*'],
        ]);

        $this->operator = $this->userService->create($this->account, [
            'name' => 'Operator',
            'email' => 'operator@example.org',
            'password' => 'Secret!234',
        ], (string) $operatorRole->_id);

        Sanctum::actingAs($this->operator, ['account-users:*']);

        $this->baseUrl = sprintf('api/v1/accounts/%s', $this->account->slug);
    }

    public function testStoreCreatesAccount(): void
    {
        $name = fake()->unique()->company();
        $documentNumber = fake()->unique()->numerify('###################');

        $response = $this->postJson('api/v1/accounts', [
            'name' => $name,
            'document' => [
                'type' => 'cpf',
                'number' => $documentNumber,
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.account.name', $name);

        Account::where('name', $name)->first()?->forceDelete();
    }

    public function testIndexFiltersByCurrentUser(): void
    {
        $response = $this->getJson('api/v1/accounts');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(1, $response->json('total'));
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains((string) $this->account->_id));
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
            sprintf('api/v1/accounts/%s/users/%s/roles/%s', $this->account->slug, $user->_id, $role->_id)
        );

        $attachResponse->assertOk();

        $detachResponse = $this->deleteJson(
            sprintf('api/v1/accounts/%s/users/%s/roles/%s', $this->account->slug, $user->_id, $role->_id)
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
