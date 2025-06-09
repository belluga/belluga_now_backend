<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAdminPermissionsTest extends TestCaseAuthenticated
{

    public function testRolesCreate(): void
    {
//        $roleName = "Test Admin Role";
//
//        $response = $this->rolesCreate([
//            "name" => $roleName,
//            "description" => "Role for testing purposes",
//            "permissions" => ["user.view", "user.create", "role.view"],
//        ]);
//
//        $response->assertStatus(201);
//        $response->assertJsonStructure([
//            "data" => [
//                "name",
//                "permissions",
//                "created_at",
//            ]
//        ]);
//
//        $this->role_id = $response->json()['data']['id'];

    }

    public function testRolesList(): void
    {
//        $rolesList = $this->rolesList();
//        $rolesList->assertOk();
//
//        $responseData = $rolesList->json();
//        $this->assertEquals(2, $responseData['total']);
//        $this->assertArrayHasKey('total', $responseData);
//        $this->assertArrayHasKey('data', $responseData);
//        $this->assertArrayHasKey('last_page', $responseData);
//        $this->assertArrayHasKey('current_page', $responseData);
//        $this->assertArrayHasKey('per_page', $responseData);
    }

    public function testRolesShow(): void
    {
//        $rolesShow = $this->rolesShow($this->role_id);
//        $rolesShow->assertOk();
//        $rolesShow->assertJsonStructure([
//            "data" => [
//                "name",
//                "permissions",
//                "created_at",
//            ],
//        ]);
    }

    public function testRolesUpdate(): void
    {
//        $roleUpdate = $this->rolesUpdate(
//            $this->role_id,
//            [
//                "name" => "Updated Role Name",
//                "permissions" => ["user.view", "user.create", "role.view", "role.create"],
//            ]
//        );
//
//        $roleUpdate->assertStatus(200);
//
//        $rolesShow = $this->rolesShow($this->role_id);
//        $rolesShow->assertOk();
//
//        $this->assertEquals("Updated Role Name", $rolesShow->json()['data']['name']);
//        $this->assertEquals(
//            ["user.view", "user.create", "role.view", "role.create"],
//            $rolesShow->json()['data']['permissions']
//        );
    }

    public function testRolesDelete(): void
    {
//        $deleteResponse = $this->rolesDelete($this->role_id);
//        $deleteResponse->assertStatus(200);
//
//        $showResponse = $this->rolesShow($this->role_id);
//        $showResponse->assertStatus(404);
    }

    public function testRolesRestore(): void
    {
//        $restoreResponse = $this->rolesRestore($this->role_id);
//        $restoreResponse->assertStatus(200);
//
//        $showResponse = $this->rolesShow($this->role_id);
//        $showResponse->assertOk();
    }

    public function testRolesDeleteFlow(): void
    {
//        $deleteResponse = $this->rolesDelete($this->role_id);
//        $deleteResponse->assertStatus(200);
//
//        $showResponse = $this->rolesShow($this->role_id);
//        $showResponse->assertStatus(404);
//
//        $rolesListArchived = $this->rolesListArchived();
//        $rolesListArchived->assertOk();
//
//        $responseData = $rolesListArchived->json();
//        $this->assertEquals(1, $responseData['total']);
//        $this->assertArrayHasKey('total', $responseData);
//        $this->assertArrayHasKey('data', $responseData);
//        $this->assertArrayHasKey('last_page', $responseData);
//        $this->assertArrayHasKey('current_page', $responseData);
//        $this->assertArrayHasKey('per_page', $responseData);
//
//        $rolesListArchived = $this->forceDelete($this->role_id);
//        $rolesListArchived->assertOk();
//
//        $rolesListArchived = $this->rolesListArchived();
//        $responseData = $rolesListArchived->json();
//        $this->assertEquals(0, $responseData['total']);
//
//        $rolesList = $this->rolesList();
//        $responseData = $rolesList->json();
//        $this->assertEquals(1, $responseData['total']);
    }

    protected function rolesList(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/roles",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesListArchived(): TestResponse
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

    protected function rolesCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/roles",
            data: $data,
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

    protected function rolesDelete(string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "admin/api/roles/$roleId",
            data: [
                "role_id" => $this->main_role_id,
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

    protected function rolesRestore(string $roleId): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/roles/$roleId/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantRolesList(string $tenant_slug): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/tenants/$tenant_slug/roles",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantRolesCreate(string $tenant_slug, array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/tenants/$tenant_slug/roles",
            data: $data,
            headers: $this->getHeaders(),
        );
    }
}
