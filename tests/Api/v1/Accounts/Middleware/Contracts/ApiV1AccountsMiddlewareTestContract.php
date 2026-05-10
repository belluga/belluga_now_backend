<?php

namespace Tests\Api\v1\Accounts\Middleware\Contracts;

use App\Application\Auth\TenantScopedAccessTokenService;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Api\Traits\AccountAuthFunctions;
use Tests\Api\Traits\AdminAuthFunctions;
use Tests\Api\Traits\AdminRoleFunctions;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;
use Tests\Helpers\UserLabels;
use Tests\TestCaseAccount;

abstract class ApiV1AccountsMiddlewareTestContract extends TestCaseAccount
{
    use AccountAuthFunctions, AdminAuthFunctions, AdminRoleFunctions;

    protected static array $seededFixtures = [];

    abstract protected TenantLabels $tenant_cross {
        get;
    }

    abstract protected AccountLabels $account_cross {
        get;
    }

    protected string $base_api_url {
        get{
            return $this->base_api_account.'roles';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! array_key_exists(static::class, static::$seededFixtures)) {
            $this->seedMiddlewareFixtures();
            static::$seededFixtures[static::class] = true;
        }
    }

    public function testLoginAllAdminUsers(): void
    {
        $response = $this->adminLogin($this->tenant->user_admin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant->user_roles_manager);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant->user_users_manager);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginCrossAdmin(): void
    {
        $response = $this->adminLogin($this->tenant_cross->user_admin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant_cross->user_roles_manager);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant_cross->user_users_manager);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant_cross->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginAccountUsers(): void
    {
        $response = $this->accountLogin($this->account->user_admin);
        $response->assertStatus(200);

        $response = $this->accountLogin($this->account->user_users_manager);
        $response->assertStatus(200);

        $response = $this->accountLogin($this->account->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginCrossAccountUsers(): void
    {

        $response = $this->accountLogin($this->account_cross->user_admin);
        $response->assertStatus(200);

        $response = $this->accountLogin($this->account_cross->user_users_manager);
        $response->assertStatus(200);

        $response = $this->accountLogin($this->account_cross->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginAccountFromCrossTenantInProperTenant(): void
    {
        $response = $this->accountLoginRaw(
            $this->tenant_cross,
            $this->tenant_cross->account_primary->user_admin);
        $response->assertStatus(200);

        $response = $this->accountLoginRaw(
            $this->tenant_cross,
            $this->tenant_cross->account_primary->user_users_manager);
        $response->assertStatus(200);

        $response = $this->accountLoginRaw(
            $this->tenant_cross,
            $this->tenant_cross->account_primary->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginAccountFromCrossTenantInWrongTenant(): void
    {
        $response = $this->accountLogin($this->tenant_cross->account_primary->user_admin);
        $response->assertStatus(403);

        $response = $this->accountLogin($this->tenant_cross->account_primary->user_users_manager);
        $response->assertStatus(403);

        $response = $this->accountLogin($this->tenant_cross->account_primary->user_visitor);
        $response->assertStatus(403);
    }

    public function testListAccountAdmin(): void
    {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->account->user_admin
            ));

        $rolesList->assertStatus(200);
    }

    public function testListAccountVisitor(): void
    {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->account->user_visitor
            ));

        $rolesList->assertStatus(403);
    }

    public function testListCrossAccountAdmin(): void
    {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->account_cross->user_admin
            )
        );

        $rolesList->assertStatus(401);
    }

    public function testListCrossAccountVisitor(): void
    {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->account_cross->user_visitor
            )
        );

        $rolesList->assertStatus(401);
    }

    public function testListTenantAdmin(): void
    {

        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->tenant->user_admin
            )
        );
        $rolesList->assertStatus(200);
    }

    public function testListTenantUserAdminNoPermissions(): void
    {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->tenant->user_users_manager
            )
        );
        $rolesList->assertStatus(403);
    }

    public function testListTenantWithoutTenantAccess(): void
    {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->tenant_cross->user_admin
            )
        );
        $rolesList->assertStatus(401);
    }

    public function testListTenantWithCrossAccessIsUnauthorizedOnAccountScope(): void
    {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->landlord->user_cross_tenant_admin
            )
        );
        $rolesList->assertStatus(401);
    }

    protected function getUserHeaders(UserLabels $user): array
    {
        return [
            'Authorization' => "Bearer $user->token",
            'Content-Type' => 'application/json',
        ];
    }

    protected function create(array $headers, array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: $this->base_api_url,
            data: $data,
            headers: $headers,
        );
    }

    protected function list(array $headers): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: $this->base_api_url,
            headers: $headers,
        );
    }

    protected function listArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "$this->base_api_url?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesShow(string $roleId): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "$this->base_api_url/$roleId",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesUpdate(string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "$this->base_api_url/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function deleteItem(string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "$this->base_api_url/$roleId",
            data: [
                //                "role_id" => $this->main_role_id,
            ],
            headers: $this->getHeaders(),
        );
    }

    protected function forceDelete(string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "$this->base_api_url/$roleId/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function restore(string $roleId): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "$this->base_api_url/$roleId/restore",
            headers: $this->getHeaders(),
        );
    }

    private function seedMiddlewareFixtures(): void
    {
        $tenant = $this->resolveOrCreateTenant($this->tenant);
        $crossTenant = $this->resolveOrCreateTenant($this->tenant_cross);

        $this->seedTenantUsers($tenant, $this->tenant);
        $this->seedTenantUsers($crossTenant, $this->tenant_cross);

        $this->seedAccountFixtures($tenant, $this->tenant->account_primary);
        $this->seedAccountFixtures($tenant, $this->tenant->account_secondary);
        $this->seedAccountFixtures($crossTenant, $this->tenant_cross->account_primary);
        $this->seedAccountFixtures($crossTenant, $this->tenant_cross->account_secondary);

        Account::current()?->forget();
        $tenant->makeCurrent();
    }

    private function resolveOrCreateTenant(TenantLabels $labels): Tenant
    {
        $tenant = Tenant::query()
            ->where('subdomain', $labels->subdomain)
            ->first();

        if (! $tenant instanceof Tenant) {
            $tenant = Tenant::create([
                'name' => $labels->name,
                'subdomain' => $labels->subdomain,
                'app_domains' => [],
            ]);
        }

        $labels->id = (string) $tenant->_id;
        $labels->slug = $tenant->slug;
        $labels->subdomain = $tenant->subdomain;

        return $tenant->fresh();
    }

    private function seedTenantUsers(Tenant $tenant, TenantLabels $labels): void
    {
        $this->seedTenantUser(
            tenant: $tenant,
            label: $labels->user_admin,
            name: 'Tenant Admin',
            emailLocalPart: 'tenant-admin',
            password: 'Secret!234',
            rolePermissions: ['account-roles:view'],
            tokenAbilities: ['account-roles:view'],
        );
        $this->seedTenantUser(
            tenant: $tenant,
            label: $labels->user_roles_manager,
            name: 'Tenant Roles Manager',
            emailLocalPart: 'tenant-roles-manager',
            password: 'Secret!234',
            rolePermissions: ['tenant-roles:view'],
            tokenAbilities: ['tenant-roles:view'],
        );
        $this->seedTenantUser(
            tenant: $tenant,
            label: $labels->user_users_manager,
            name: 'Tenant Users Manager',
            emailLocalPart: 'tenant-users-manager',
            password: 'Secret!234',
            rolePermissions: ['tenant-users:view'],
            tokenAbilities: ['tenant-users:view'],
        );
        $this->seedTenantUser(
            tenant: $tenant,
            label: $labels->user_visitor,
            name: 'Tenant Visitor',
            emailLocalPart: 'tenant-visitor',
            password: 'Secret!234',
            rolePermissions: [],
            tokenAbilities: [],
        );
    }

    private function seedTenantUser(
        Tenant $tenant,
        UserLabels $label,
        string $name,
        string $emailLocalPart,
        string $password,
        array $rolePermissions,
        array $tokenAbilities,
    ): void {
        $email = sprintf('%s-%s@middleware.test', $tenant->subdomain, $emailLocalPart);
        $passwordHash = Hash::make($password);

        $user = LandlordUser::query()
            ->where('emails', 'all', [$email])
            ->first();

        if (! $user instanceof LandlordUser) {
            $user = LandlordUser::create([
                'name' => $name,
                'emails' => [$email],
                'identity_state' => 'registered',
            ]);
        }

        $user->name = $name;
        $user->emails = [$email];
        $user->identity_state = 'registered';
        $user->tenant_roles = [[
            'name' => $name,
            'slug' => Str::slug($name),
            'permissions' => $rolePermissions,
            'tenant_id' => (string) $tenant->_id,
        ]];
        $user->save();
        $user->syncCredential('password', $email, $passwordHash);

        // Preserve compatibility with both the clean RR-AUTH-03 baseline and
        // the unrelated RR-AUTH-01 dirty principal auth path.
        LandlordUser::query()
            ->whereKey($user->getKey())
            ->update([
                'password' => $passwordHash,
                'password_type' => 'laravel',
            ]);

        $label->name = $name;
        $label->email_1 = $email;
        $label->email_2 = '';
        $label->password = $password;
        $label->password_reset_token = '';
        $label->user_id = (string) $user->_id;
        $label->token = $user->createToken(
            sprintf('middleware-%s', $emailLocalPart),
            $tokenAbilities,
        )->plainTextToken;
    }

    private function seedAccountFixtures(Tenant $tenant, AccountLabels $labels): void
    {
        $tenant->makeCurrent();

        $seedKey = $this->labelSeedKey($labels);
        $account = Account::create([
            'name' => sprintf('%s %s', Str::afterLast(static::class, '\\'), $seedKey),
            'document' => strtoupper(substr(md5(static::class.$seedKey), 0, 14)),
        ]);

        $labels->id = (string) $account->_id;
        $labels->name = $account->name;
        $labels->document = $account->document;
        $labels->slug = $account->slug;

        $this->seedAccountUser(
            tenant: $tenant,
            account: $account,
            label: $labels->user_admin,
            name: 'Account Admin',
            emailLocalPart: 'account-admin',
            password: 'Secret!234',
            permissions: ['account-roles:view'],
        );
        $this->seedAccountUser(
            tenant: $tenant,
            account: $account,
            label: $labels->user_users_manager,
            name: 'Account Users Manager',
            emailLocalPart: 'account-users-manager',
            password: 'Secret!234',
            permissions: ['account-users:view'],
        );
        $this->seedAccountUser(
            tenant: $tenant,
            account: $account,
            label: $labels->user_visitor,
            name: 'Account Visitor',
            emailLocalPart: 'account-visitor',
            password: 'Secret!234',
            permissions: [],
        );
    }

    private function seedAccountUser(
        Tenant $tenant,
        Account $account,
        UserLabels $label,
        string $name,
        string $emailLocalPart,
        string $password,
        array $permissions,
    ): void {
        $tenant->makeCurrent();
        $account->makeCurrent();

        $email = sprintf('%s-%s@middleware.test', $account->slug, $emailLocalPart);
        $user = $account->users()->create([
            'name' => $name,
            'emails' => [$email],
            'password' => $password,
            'identity_state' => 'registered',
        ]);

        $user->account_roles = [[
            'account_id' => (string) $account->_id,
            'permissions' => $permissions,
            'name' => $name,
        ]];
        $user->save();

        $label->name = $name;
        $label->email_1 = $email;
        $label->email_2 = '';
        $label->password = $password;
        $label->password_reset_token = '';
        $label->user_id = (string) $user->_id;
        $label->token = $this->app->make(TenantScopedAccessTokenService::class)
            ->issueForAccountUser(
                $user,
                sprintf('middleware-%s', $emailLocalPart),
                $permissions,
                accountId: (string) $account->_id,
            )
            ->plainTextToken;
    }

    private function labelSeedKey(AccountLabels $label): string
    {
        $property = new \ReflectionProperty($label, 'base_label');
        $property->setAccessible(true);

        return (string) $property->getValue($label);
    }
}
