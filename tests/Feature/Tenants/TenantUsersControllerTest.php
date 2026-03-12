<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Accounts\AccountUserService;
use App\Application\Accounts\TenantUserManagementService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class TenantUsersControllerTest extends TestCaseTenant
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

    private AccountRoleTemplate $role;

    private AccountUserService $userService;

    private TenantUserManagementService $tenantUserService;

    private string $baseUrl;

    private array $headers;

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

        $this->userService = $this->app->make(AccountUserService::class);
        $this->tenantUserService = $this->app->make(TenantUserManagementService::class);

        $tenant = Tenant::query()->where('subdomain', 'tenant-theta')->firstOrFail();
        $tenant->makeCurrent();
        $this->baseUrl = "{$this->base_tenant_api_admin}users";
        $this->headers = $this->getHeaders();
    }

    public function test_index_returns_paginated_users(): void
    {
        $response = $this->withHeaders($this->headers)->getJson($this->baseUrl);

        $response->assertOk();
        $response->assertJsonStructure(['data', 'total', 'per_page', 'current_page']);
    }

    public function test_index_filters_by_email(): void
    {
        $target = $this->createUser('filter@example.org');
        $target->name = 'Filter Match';
        $target->save();

        $this->createUser('another@example.org');

        $response = $this->withHeaders($this->headers)
            ->getJson($this->baseUrl.'?filter[emails]='.urlencode('filter@example.org'));

        $response->assertOk();
        $this->assertSame('Filter Match', $response->json('data.0.name'));
    }

    public function test_index_sorts_by_name_descending(): void
    {
        $alpha = $this->createUser('alpha@example.org');
        $alpha->name = 'Alpha User';
        $alpha->save();

        $zulu = $this->createUser('zulu@example.org');
        $zulu->name = 'Zulu User';
        $zulu->save();

        $response = $this->withHeaders($this->headers)->getJson($this->baseUrl.'?sort=-name');

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Alpha User', $names);
        $this->assertContains('Zulu User', $names);
        $this->assertSame('Zulu User', $names[0]);
    }

    public function test_index_ignores_unsupported_sort_and_uses_default(): void
    {
        $baseline = $this->withHeaders($this->headers)->getJson($this->baseUrl);
        $fallback = $this->withHeaders($this->headers)->getJson($this->baseUrl.'?sort=-unsupported');

        $this->assertNotNull($baseline->json('data.0.id'));
        $this->assertSame(
            $baseline->json('data.0.id'),
            $fallback->json('data.0.id')
        );
    }

    public function test_show_returns_single_user(): void
    {
        $user = $this->createUser('show@example.org');

        $response = $this->withHeaders($this->headers)
            ->getJson(sprintf('%s/%s', $this->baseUrl, $user->_id));

        $response->assertOk();
        $response->assertJsonPath('data.id', (string) $user->_id);
    }

    public function test_destroy_soft_deletes_user(): void
    {
        $user = $this->createUser('delete@example.org');

        $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s', $this->baseUrl, $user->_id))
            ->assertOk();

        $this->assertSoftDeleted('account_users', ['_id' => $user->_id], 'tenant');
    }

    public function test_restore_revives_user(): void
    {
        $user = $this->createUser('restore@example.org');
        $this->tenantUserService->delete((string) $user->_id);

        $this->withHeaders($this->headers)
            ->postJson(sprintf('%s/%s/restore', $this->baseUrl, $user->_id))
            ->assertOk();

        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_force_destroy_removes_user(): void
    {
        $user = $this->createUser('force@example.org');
        $this->tenantUserService->delete((string) $user->_id);

        $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s/force_destroy', $this->baseUrl, $user->_id))
            ->assertOk();

        $this->assertDatabaseMissing('account_users', ['_id' => $user->_id], 'tenant');
    }

    private function createUser(string $email): AccountUser
    {
        return $this->userService->create($this->account, [
            'name' => 'Sample User',
            'email' => $email,
            'password' => 'Secret!234',
        ], (string) $this->role->_id);
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Theta', 'subdomain' => 'tenant-theta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-theta.test']
        );

        $service->initialize($payload);
    }
}
