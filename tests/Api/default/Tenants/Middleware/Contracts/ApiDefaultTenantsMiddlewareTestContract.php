<?php

namespace Tests\Api\default\Tenants\Middleware\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Api\default\Accounts\Traits\AccountAuthFunctions;
use Tests\Api\default\Admin\Traits\AdminAuthFunctions;
use Tests\Api\default\Admin\Traits\AdminRoleFunctions;
use Tests\Helpers\TenantLabels;
use Tests\Helpers\UserLabels;
use Tests\TestCaseTenant;

abstract class ApiDefaultTenantsMiddlewareTestContract extends TestCaseTenant
{
    use AdminRoleFunctions, AdminAuthFunctions, AccountAuthFunctions;

    abstract protected TenantLabels $tenant_cross {
        get;
    }

    public function testLoginAllAdminUsers(): void {
        $response = $this->adminLogin($this->landlord->user_superadmin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->landlord->user_cross_tenant_visitor);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->landlord->user_cross_tenant_admin);
        $response->assertStatus(200);
    }

    public function testLoginTenantUsers(): void {
        $response = $this->adminLogin($this->tenant->user_admin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant->user_roles_manager);
        $response->assertStatus(200);
    }

    public function testLoginCrossTenantUsers(): void {
        $response = $this->adminLogin($this->tenant_cross->user_admin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant_cross->user_roles_manager);
        $response->assertStatus(200);
    }

    public function testLoginAccountUser():void {
        $response = $this->accountLogin($this->tenant->account_primary->user_admin);
        $response->assertStatus(200);
    }

    public function testLoginAccountUserCross():void {

        $response = $this->accountLogin($this->tenant_cross->account_primary->user_admin);
        $response->assertStatus(403);

        $response = $this->accountLoginRaw(
            $this->tenant_cross,
            $this->tenant_cross->account_primary->user_admin);
        $response->assertStatus(200);
    }

    public function testListWithAdmin(): void {
        $rolesList = $this->list(
            $this->getHeader($this->tenant->user_admin)
        );

        $rolesList->assertStatus(200);
    }

    public function testListCrossNoPermission(): void {
        $rolesList = $this->list(
            $this->getHeader($this->landlord->user_cross_tenant_visitor)
        );

        $rolesList->assertStatus(403);
    }

    public function testListWithPermissionCrossTenant(): void {
        $rolesList = $this->list(
            $this->getHeader($this->landlord->user_cross_tenant_admin)
        );

        $rolesList->assertStatus(200);
    }

    public function testListWithAccountPermission(): void {
        $rolesList = $this->list(
            $this->getHeader($this->tenant->account_primary->user_admin)
        );

        $rolesList->assertStatus(401);
    }

    public function testListWithPermissionWithoutTenant(): void {
        $rolesList = $this->list(
            $this->getHeader($this->tenant_cross->user_admin)
        );

        $rolesList->assertStatus(403);
    }

    protected function getHeader(UserLabels $user): array {
        return [
            'Authorization' => "Bearer $user->token",
            'Content-Type' => 'application/json'
        ];
    }

    protected function create(array $headers, array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant->subdomain}.localhost/api/roles",
            data: $data,
            headers: $headers,
        );
    }

    protected function list(array $headers): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant->subdomain}.localhost/api/roles",
            headers: $headers,
        );
    }

}
