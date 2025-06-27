<?php

namespace Tests\Api\default\Accounts\Middleware\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Api\default\Accounts\Contracts\TestCaseAccount;
use Tests\Api\default\Accounts\Traits\AccountAuthFunctions;
use Tests\Api\default\Admin\Traits\AdminAuthFunctions;
use Tests\Api\default\Admin\Traits\AdminRoleFunctions;
use Tests\Helpers\AccountLabels;
use Tests\Helpers\TenantLabels;
use Tests\Helpers\UserLabels;

abstract class ApiDefaultAccountsMiddlewareTestContract extends TestCaseAccount
{
    use AdminRoleFunctions, AdminAuthFunctions, AccountAuthFunctions;

    abstract protected TenantLabels $tenant_cross {
        get;
    }

    abstract protected AccountLabels $account_cross {
        get;
    }

    protected string $base_api_url {
        get{
            return $this->base_api."roles";
        }
    }

    public function testLoginAllAdminUsers(): void {
        $response = $this->adminLogin($this->tenant->user_admin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant->user_roles_manager);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant->user_users_manager);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginCrossAdmin(): void {
        $response = $this->adminLogin($this->tenant_cross->user_admin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant_cross->user_roles_manager);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant_cross->user_users_manager);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->tenant_cross->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginAccountUsers():void {
        $response = $this->accountLogin($this->account->user_admin);
        $response->assertStatus(200);

        $response = $this->accountLogin($this->account->user_users_manager);
        $response->assertStatus(200);

        $response = $this->accountLogin($this->account->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginCrossAccountUsers():void {

        print("testLoginCrossAdmin > ");

        print_r($this->account_cross->user_admin);

        $response = $this->accountLogin($this->account_cross->user_admin);
        $response->assertStatus(200);

        $response = $this->accountLogin($this->account_cross->user_users_manager);
        $response->assertStatus(200);

        $response = $this->accountLogin($this->account_cross->user_visitor);
        $response->assertStatus(200);
    }

    public function testLoginAccountFromCrossTenantInProperTenant():void {
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

    public function testLoginAccountFromCrossTenantInWrongTenant():void {
        $response = $this->accountLogin($this->tenant_cross->account_primary->user_admin);
        $response->assertStatus(403);

        $response = $this->accountLogin($this->tenant_cross->account_primary->user_users_manager);
        $response->assertStatus(403);

        $response = $this->accountLogin($this->tenant_cross->account_primary->user_visitor);
        $response->assertStatus(403);
    }

    public function testListAccountAdmin(): void {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->account->user_admin
            ));

        $rolesList->assertStatus(200);
    }

    public function testListAccountVisitor(): void {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->account->user_visitor
            ));

        $rolesList->assertStatus(403);
    }

    public function testListCrossAccountAdmin(): void {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->account_cross->user_admin
            )
        );

        $rolesList->assertStatus(401);
    }

    public function testListCrossAccountVisitor(): void {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->account_cross->user_visitor
            )
        );

        $rolesList->assertStatus(401);
    }

    public function testListTenantAdmin(): void {

        print("testListTenantAdmin > ");

        print_r([
            "user" => [
                "id" => $this->tenant->user_admin->user_id,
                "name" => $this->tenant->user_admin->name,
            ],
            "role" => [

            ]
        ]);

        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->tenant->user_admin
            )
        );
        $rolesList->assertStatus(200);
    }

    public function testListTenantUserAdminNoPermissions(): void {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->tenant->user_users_manager
            )
        );
        $rolesList->assertStatus(403);
    }

    public function testListTenantWithoutTenantAccess(): void {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->tenant_cross->user_admin
            )
        );
        $rolesList->assertStatus(401);
    }

    public function testListTenantWithCrossAccess(): void {
        $rolesList = $this->list(
            $this->getUserHeaders(
                $this->landlord->user_cross_tenant_admin
            )
        );
        $rolesList->assertStatus(200);
    }

    protected function getUserHeaders(UserLabels $user): array {
        return [
            'Authorization' => "Bearer $user->token",
            'Content-Type' => 'application/json'
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

}
