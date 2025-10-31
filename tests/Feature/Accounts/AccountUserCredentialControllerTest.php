<?php

declare(strict_types=1);

namespace Tests\Feature\Accounts;

use App\Application\Accounts\AccountRoleTemplateService;
use App\Application\Accounts\AccountUserCredentialService;
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

class AccountUserCredentialControllerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplate $role;

    private AccountUserService $userService;

    private AccountRoleTemplateService $roleService;

    private AccountUserCredentialService $credentialService;

    private AccountUser $operator;

    private string $baseUrl;

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
            'account-users:credentials',
        ]);
        $this->account->makeCurrent();

        $this->userService = $this->app->make(AccountUserService::class);
        $this->roleService = $this->app->make(AccountRoleTemplateService::class);
        $this->credentialService = $this->app->make(AccountUserCredentialService::class);

        $operatorRole = $this->roleService->create($this->account, [
            'name' => 'Operator',
            'permissions' => [
                'account-users:view',
                'account-users:update',
                'account-users:create',
                'account-users:delete',
            ],
        ]);

        $this->operator = $this->userService->create($this->account, [
            'name' => 'Operator',
            'email' => 'operator@example.org',
            'password' => 'Secret!234',
        ], (string) $operatorRole->_id);

        Sanctum::actingAs($this->operator, [
            'account-users:view',
            'account-users:update',
            'account-users:create',
            'account-users:delete',
        ]);

        $this->baseUrl = sprintf('api/v1/accounts/%s/users', $this->account->slug);
    }

    public function testStoreLinksCredential(): void
    {
        $email = 'user+' . uniqid('', true) . '@example.org';
        $user = $this->createUser($email);

        $response = $this->postJson(
            sprintf('%s/%s/credentials', $this->baseUrl, $user->_id),
            [
                'provider' => 'password',
                'subject' => $email,
                'secret' => 'Secret!234',
            ]
        );

        $response->assertCreated();
        $response->assertJsonPath('data.credential.provider', 'password');
    }

    public function testStoreRejectsDuplicateCredential(): void
    {
        $user = $this->createUser('primary+' . uniqid('', true) . '@example.org');
        $duplicateSubject = 'duplicate+' . uniqid('', true) . '@example.org';
        $anotherUser = $this->createUser('another+' . uniqid('', true) . '@example.org');

        $this->postJson(
            sprintf('%s/%s/credentials', $this->baseUrl, $user->_id),
            [
                'provider' => 'password',
                'subject' => $duplicateSubject,
                'secret' => 'Secret!234',
            ]
        )->assertCreated();

        $response = $this->postJson(
            sprintf('%s/%s/credentials', $this->baseUrl, $anotherUser->_id),
            [
                'provider' => 'password',
                'subject' => $duplicateSubject,
                'secret' => 'Secret!234',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['subject']);
    }

    public function testDestroyUnlinksCredential(): void
    {
        $email = 'unlink+' . uniqid('', true) . '@example.org';
        $user = $this->createUser($email);
        $result = $this->credentialService->link($user, [
            'provider' => 'password',
            'subject' => $email,
            'secret' => 'Secret!234',
        ]);

        $credentialId = $result['credential']['_id'] ?? $result['credential']['id'];

        $response = $this->deleteJson(
            sprintf('%s/%s/credentials/%s', $this->baseUrl, $user->_id, $credentialId)
        );

        $response->assertOk();
        $response->assertJsonMissing(['credential' => []]);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Delta', 'subdomain' => 'tenant-delta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-delta.test']
        );

        $service->initialize($payload);
    }

    private function createUser(?string $email = null): AccountUser
    {
        $email ??= 'user+' . uniqid('', true) . '@example.org';

        return $this->userService->create($this->account, [
            'name' => 'Sample User',
            'email' => $email,
            'password' => 'Secret!234',
        ], (string) $this->role->_id);
    }
}
