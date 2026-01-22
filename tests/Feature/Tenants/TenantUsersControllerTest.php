<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Application\Accounts\AccountRoleTemplateService;
use App\Application\Accounts\AccountUserService;
use App\Application\Accounts\TenantUserManagementService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;
use Tests\Helpers\TenantLabels;

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

    public function testIndexReturnsPaginatedUsers(): void
    {
        $response = $this->withHeaders($this->headers)->getJson($this->baseUrl);

        $response->assertOk();
        $response->assertJsonStructure(['data', 'total', 'per_page', 'current_page']);
    }

    public function testIndexFiltersByEmail(): void
    {
        $target = $this->createUser('filter@example.org');
        $target->name = 'Filter Match';
        $target->save();

        $this->createUser('another@example.org');

        $response = $this->withHeaders($this->headers)
            ->getJson($this->baseUrl . '?filter[emails]=' . urlencode('filter@example.org'));

        $response->assertOk();
        $this->assertSame('Filter Match', $response->json('data.0.name'));
    }

    public function testIndexSortsByNameDescending(): void
    {
        $alpha = $this->createUser('alpha@example.org');
        $alpha->name = 'Alpha User';
        $alpha->save();

        $zulu = $this->createUser('zulu@example.org');
        $zulu->name = 'Zulu User';
        $zulu->save();

        $response = $this->withHeaders($this->headers)->getJson($this->baseUrl . '?sort=-name');

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Alpha User', $names);
        $this->assertContains('Zulu User', $names);
        $this->assertSame('Zulu User', $names[0]);
    }

    public function testIndexIgnoresUnsupportedSortAndUsesDefault(): void
    {
        $baseline = $this->withHeaders($this->headers)->getJson($this->baseUrl);
        $fallback = $this->withHeaders($this->headers)->getJson($this->baseUrl . '?sort=-unsupported');

        $this->assertNotNull($baseline->json('data.0.id'));
        $this->assertSame(
            $baseline->json('data.0.id'),
            $fallback->json('data.0.id')
        );
    }

    public function testShowReturnsSingleUser(): void
    {
        $user = $this->createUser('show@example.org');

        $response = $this->withHeaders($this->headers)
            ->getJson(sprintf('%s/%s', $this->baseUrl, $user->_id));

        $response->assertOk();
        $response->assertJsonPath('data.id', (string) $user->_id);
    }

    public function testDestroySoftDeletesUser(): void
    {
        $user = $this->createUser('delete@example.org');

        $this->withHeaders($this->headers)
            ->deleteJson(sprintf('%s/%s', $this->baseUrl, $user->_id))
            ->assertOk();

        $this->assertSoftDeleted('account_users', ['_id' => $user->_id], 'tenant');
    }

    public function testRestoreRevivesUser(): void
    {
        $user = $this->createUser('restore@example.org');
        $this->tenantUserService->delete((string) $user->_id);

        $this->withHeaders($this->headers)
            ->postJson(sprintf('%s/%s/restore', $this->baseUrl, $user->_id))
            ->assertOk();

        $this->assertFalse($user->fresh()->trashed());
    }

    public function testForceDestroyRemovesUser(): void
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
