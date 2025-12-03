<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountUserControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $databaseBootstrapped = false;

    private static bool $systemInitialized = false;

    private Account $account;

    private string $baseUrl;

    private AccountUserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$databaseBootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            self::$databaseBootstrapped = true;
        }

        if (! self::$systemInitialized) {
            $this->initializeSystem();
            self::$systemInitialized = true;
        }

        [$this->account, $roleTemplate] = $this->seedAccountWithRole([
            'account-users:*',
        ]);
        $this->account->makeCurrent();

        $this->service = $this->app->make(AccountUserService::class);

        $operator = $this->service->create(
            $this->account,
            [
                'name' => 'Operator User',
                'email' => 'operator@example.org',
                'password' => 'Secret!234',
            ],
            (string) $roleTemplate->_id
        );

        Sanctum::actingAs($operator, [
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        $this->baseUrl = sprintf('api/v1/accounts/%s/users', $this->account->slug);
    }

    public function testStoreCreatesAccountUser(): void
    {
        $response = $this->postJson($this->baseUrl, [
            'name' => 'New Account User',
            'email' => 'new-user@example.org',
            'password' => 'Secret!234',
            'role_id' => (string) $this->account->roleTemplates()->first()->_id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'New Account User');

        $this->assertDatabaseHas('account_users', [
            'name' => 'New Account User',
        ], 'tenant');
    }

    public function testUpdateRejectsEmptyPayload(): void
    {
        $user = $this->createAccountUser();

        $response = $this->patchJson($this->baseUrl . '/' . $user->_id, []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.empty.0', 'Nenhum dado recebido para atualizar.');
    }

    public function testDestroyRevokesAccountAccess(): void
    {
        $user = $this->createAccountUser();

        $deleteResponse = $this->deleteJson($this->baseUrl . '/' . $user->_id);
        $deleteResponse->assertOk();

        $this->assertSoftDeleted('account_users', ['_id' => $user->_id], 'tenant');
    }

    public function testIndexFiltersByName(): void
    {
        $target = $this->createAccountUser();
        $target->name = 'Filtered User';
        $target->save();

        $this->createAccountUser();

        $response = $this->getJson($this->baseUrl . '?filter[name]=Filtered User');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame('Filtered User', $data[0]['name']);
    }

    public function testIndexSortsByNameDescending(): void
    {
        $alpha = $this->createAccountUser();
        $alpha->name = 'Alpha User';
        $alpha->save();

        $zulu = $this->createAccountUser();
        $zulu->name = 'Zulu User';
        $zulu->save();

        $response = $this->getJson($this->baseUrl . '?sort=-name');

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');

        $this->assertContains('Alpha User', $names);
        $this->assertContains('Zulu User', $names);
        $this->assertSame('Zulu User', $names[0]);
    }

    private function createAccountUser(): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Account Visitor ' . uniqid(),
            'permissions' => ['account-users:view'],
        ]);

        return $this->service->create(
            $this->account,
            [
                'name' => 'Fixture User',
                'email' => 'fixture+' . uniqid('', true) . '@example.org',
                'password' => 'Secret!234',
            ],
            (string) $role->_id
        );
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Beta', 'subdomain' => 'tenant-beta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-beta.test']
        );

        $service->initialize($payload);
    }
}
