<?php

namespace Tests\Api\default\Accounts;

use Illuminate\Testing\TestResponse;
use Tests\Api\default\Accounts\Traits\AccountAuthFunctions;
use Tests\Api\default\Admin\Traits\AdminAuthFunctions;
use Tests\Api\default\Admin\Traits\AdminRoleFunctions;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;
use Tests\TestCase;

class ApiDefaultAccountsMiddlewareTest extends TestCase
{
    use AdminRoleFunctions, AdminAuthFunctions, AccountAuthFunctions;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected TenantLabels $tenant_cross {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected AccountLabels $account {
        get {
            return $this->landlord->tenant_primary->account_primary;
        }
    }

    protected AccountLabels $account_cross {
        get {
            return $this->landlord->tenant_primary->account_secondary;
        }
    }

    public function testLoginAllAdminUsers(): void {
        $response = $this->adminLogin($this->tenant->user_admin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant->user_visitor);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->landlord->user_tenant_manager);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->landlord->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginCrossAdmin(): void {
        $response = $this->adminLogin($this->landlord->user_superadmin);
        $response->assertStatus(200);
    }

    public function testLoginAccountUsers():void {
        $response = $this->tenantLogin($this->account->user_admin);
        $response->assertStatus(200);

        $response = $this->tenantLogin($this->account->user_users_manager);
        $response->assertStatus(200);

        $response = $this->tenantLogin($this->account->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginCrossAccountUsers():void {
        $response = $this->tenantLogin($this->account_cross->user_admin);
        $response->assertStatus(200);

        $response = $this->tenantLogin($this->account_cross->user_users_manager);
        $response->assertStatus(200);

        $response = $this->tenantLogin($this->account_cross->user_visitor);
        $response->assertStatus(200);
    }

    public function testListNoPermission(): void {
        $rolesList = $this->list(
            $this->getHeadersLandlordUserWithoutAccess()
        );

        $rolesList->assertStatus(403);
    }

    public function testListWithAccountPermission(): void {
        $rolesList = $this->list(
            $this->getHeadersAccountUser()
        );

        $rolesList->assertStatus(401);
    }

    public function testListWithPermissionWithoutTenant(): void {
        $rolesList = $this->list(
            $this->getHeadersLandlordUserWithoutAccess()
        );
        $rolesList->assertStatus(403);
    }

    protected function getHeadersLandlordUserWithAccess(): array {

        $token = $this->landlord->user_superadmin->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }

    protected function getHeadersLandlordUserWithoutAccess(): array {

        $token = $this->landlord->user_visitor->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }

    protected function getHeadersAccountUser(): array {

        $token = $this->landlord->tenant_primary->account_primary->user_admin->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }

    protected function create(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/roles",
            data: $data,
            headers: $this->getHeadersLandlordUserWithAccess(),
        );
    }

    protected function list(array $headers): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/roles",
            headers: $headers,
        );
    }

    protected function listArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/roles?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesShow(string $roleId): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/roles/$roleId",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesUpdate(string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "admin/api/roles/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function deleteItem(string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "admin/api/roles/$roleId",
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
            uri: "admin/api/roles/$roleId/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function restore(string $roleId): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/roles/$roleId/restore",
            headers: $this->getHeaders(),
        );
    }

}
