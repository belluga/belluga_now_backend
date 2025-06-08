<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultTenantRolesValidationTest extends TestCaseAuthenticated
{
    public function testTenantRolesList(): void
    {
//        $rolesList = $this->accountRolesList($this->main_account_slug);
//        $rolesList->assertOk();
//
//        $responseData = $rolesList->json();
//        $this->assertArrayHasKey('total', $responseData);
//        $this->equalTo(0, $responseData['total']);
//        $this->assertArrayHasKey('data', $responseData);
    }

    public function testTenantRolesCreate(): void
    {

//        $response = $this->accountRolesCreate(
//            $this->main_account_slug,
//            [
//                "name" => "Account Editor Role",
//                "description" => "Role for account editing",
//                "permissions" => ["user.view", "user.create"],
//            ]
//        );
//
//        $response->assertStatus(201);
//        $response->assertJsonStructure([
//            "data" => [
//                "name",
//                "description",
//                "permissions",
//                "account_id",
//                "created_at",
//            ]
//        ]);
//
//        $this->secondary_role_id = $response->json()['data']['id'];
    }

    public function testTenantRolesShow(): void
    {
//        $rolesShow = $this->accountRolesShow($this->main_account_slug, $this->secondary_role_id);
//        $rolesShow->assertOk();
//        $rolesShow->assertJsonStructure([
//            "data" => [
//                "name",
//                "description",
//                "permissions",
//                "account_id",
//                "created_at",
//            ],
//        ]);
    }

    public function testTenantRolesUpdate(): void
    {
//        $roleUpdate = $this->accountRolesUpdate(
//            $this->main_account_slug,
//            $this->secondary_role_id,
//            [
//                "name" => "Updated Account Role",
//                "permissions" => ["user.view", "user.create", "user.update"],
//            ]
//        );
//
//        $roleUpdate->assertStatus(200);
//
//        $rolesShow = $this->accountRolesShow($this->main_account_slug, $this->secondary_role_id);
//        $rolesShow->assertOk();
//
//        $this->assertEquals("Updated Account Role", $rolesShow->json()['data']['name']);
//        $this->assertEquals(
//            ["user.view", "user.create", "user.update"],
//            $rolesShow->json()['data']['permissions']
//        );
    }

    public function testTenantRolesDelete(): void
    {

//        $rolesList = $this->accountRolesList($this->main_account_slug);
//        $rolesList->assertOk();
//
//        $responseData = $rolesList->json();
//        $this->assertArrayHasKey('total', $responseData);
//        $this->equalTo(2, $responseData['total']);
//
//        $deleteResponse = $this->accountRolesDelete(
//            $this->main_account_slug,
//            $this->secondary_role_id,
//            [
//                "role_id" => $this->main_role_id
//            ]
//        );
//        $deleteResponse->assertStatus(200);
//
//        $rolesList = $this->accountRolesList($this->main_account_slug);
//        $rolesList->assertOk();
//
//        $responseData = $rolesList->json();
//        $this->assertArrayHasKey('total', $responseData);
//        $this->equalTo(1, $responseData['total']);
//
//        $rolesList = $this->accountRolesListArchived($this->main_account_slug);
//        $rolesList->assertOk();
//
//        $responseData = $rolesList->json();
//        $this->assertArrayHasKey('total', $responseData);
//        $this->equalTo(1, $responseData['total']);
//
//        $showDeleted = $this->accountRolesShow($this->main_account_slug, $this->secondary_role_id);
//        $showDeleted->assertStatus(404);
    }

    public function testTenantRolesRestore(): void
    {
//        $restoreResponse = $this->accountRolesRestore($this->main_account_slug, $this->secondary_role_id);
//        $restoreResponse->assertStatus(200);
//
//        // Should be able to get the restored role
//        $showResponse = $this->accountRolesShow($this->main_account_slug, $this->secondary_role_id);
//        $showResponse->assertOk();
//
//        $rolesList = $this->accountRolesListArchived($this->main_account_slug);
//        $rolesList->assertOk();
//
//        $responseData = $rolesList->json();
//        $this->assertArrayHasKey('total', $responseData);
//        $this->equalTo(2, $responseData['total']);
    }

    public function testTenantRolesDeleteFlow(): void
    {
//        $responseListWithCreated = $this->accountRolesList($this->main_account_slug);
//        $this->assertArrayHasKey('total', $responseListWithCreated->json());;
//        $this->equalTo(2, $responseListWithCreated->json()['total']);
//
//        $responseListArchived = $this->accountRolesListArchived($this->main_account_slug);
//        $this->assertArrayHasKey('total', $responseListArchived->json());;
//        $this->equalTo(0, $responseListArchived->json()['total']);
//
//        $restoreResponse = $this->accountRolesDelete(
//            $this->main_account_slug,
//            $this->secondary_role_id,
//            [
//                "role_id" => $this->main_role_id
//            ]
//        );
//        $restoreResponse->assertStatus(200);
//
//        $responseListWithCreated = $this->accountRolesList($this->main_account_slug);
//        $this->assertArrayHasKey('total', $responseListWithCreated->json());;
//        $this->equalTo(1, $responseListWithCreated->json()['total']);
//
//        $responseListArchived = $this->accountRolesListArchived($this->main_account_slug);
//        $this->assertArrayHasKey('total', $responseListArchived->json());;
//        $this->equalTo(1, $responseListArchived->json()['total']);
//
//        $restoreResponse = $this->accountRolesForceDelete(
//            $this->main_account_slug,
//            $this->secondary_role_id,
//        );
//        $restoreResponse->assertStatus(200);
//
//        $responseListWithCreated = $this->accountRolesList($this->main_account_slug);
//        $this->assertArrayHasKey('total', $responseListWithCreated->json());;
//        $this->equalTo(1, $responseListWithCreated->json()['total']);
//
//        $responseListArchived = $this->accountRolesListArchived($this->main_account_slug);
//        $this->assertArrayHasKey('total', $responseListArchived->json());;
//        $this->equalTo(0, $responseListArchived->json()['total']);

    }

    protected function tenantRolesList(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/roles",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantRolesListArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/roles?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantRolesShow(string $roleId): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/roles/$roleId",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantRolesCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/roles",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantRolesUpdate(string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "http://{$this->tenant_subdomain}.localhost/api/roles/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantRolesDelete(string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_subdomain}.localhost/api/roles/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantRolesForceDelete(string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_subdomain}.localhost/api/roles/$roleId/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantRolesRestore(string $roleId): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/roles/$roleId/restore",
            headers: $this->getHeaders(),
        );
    }
}
