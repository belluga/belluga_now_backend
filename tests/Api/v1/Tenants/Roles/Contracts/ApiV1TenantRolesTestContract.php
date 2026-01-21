<?php

namespace Tests\Api\v1\Tenants\Roles\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\TestCaseTenant;

abstract class ApiV1TenantRolesTestContract extends TestCaseTenant
{
    public function testRoleRolesManagerCreate(): void
    {
        $this->tenant->role_roles_manager->name = "Roles Manager";

        $response = $this->rolesCreate(
            [
                "name" => $this->tenant->role_roles_manager->name,
                "description" => "Roles Manager Role",
                "permissions" => [
                    "profile:view",
                    "profile:update",
                    "landlord-user:view",
                    "landlord-user:create",
                    "landlord-user:delete",
                    "landlord-user:update"
                ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            "data" => [
                "name",
                "permissions",
                "created_at",
            ]
        ]);

        $this->tenant->role_roles_manager->id = $response->json()['data']['id'];

    }

    public function testRoleUsersManagerCreate(): void
    {
        $this->tenant->role_users_manager->name = "Users Manager";

        $response = $this->rolesCreate(
            [
            "name" => $this->tenant->role_users_manager->name,
            "description" => "Tenants Manager Role",
            "permissions" => [
                "profile:view",
                "profile:update",
                "landlord-user:view",
                "landlord-user:create",
                "landlord-user:delete",
                "landlord-user:update",
                "tenants:view",
                "tenants:create",
                "tenants:delete",
                "tenants:update",
                "tenants-roles:view",
                "tenants-roles:create",
                "tenants-roles:delete",
                "tenants-roles:update",
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            "data" => [
                "name",
                "permissions",
                "created_at",
            ]
        ]);

        $this->tenant->role_users_manager->id = $response->json()['data']['id'];

    }

    public function testRoleVisitorCreate(): void
    {
        $this->tenant->role_visitor->name = "Visitor";

        $response = $this->rolesCreate(
            [
            "name" => $this->tenant->role_visitor->name,
            "description" => "Visitor Role",
            "permissions" => [
                "profile:view",
                "profile:update",
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            "data" => [
                "name",
                "permissions",
                "created_at",
            ]
        ]);

        $this->tenant->role_visitor->id = $response->json()['data']['id'];

    }

    public function testRoleDisposableCreate(): void
    {
        $this->tenant->role_disposable->name = "Disposable";

        $response = $this->rolesCreate(
            [
            "name" => $this->tenant->role_disposable->name,
            "description" => "To be deleted",
            "permissions" => [
                "profile:view",
                "profile:update",
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            "data" => [
                "name",
                "permissions",
                "created_at",
            ]
        ]);

        $this->tenant->role_disposable->id = $response->json()['data']['id'];

    }

    public function testRolesList(): void
    {
        $rolesList = $this->rolesList();

        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertEquals(5, $responseData['total']);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('last_page', $responseData);
        $this->assertArrayHasKey('current_page', $responseData);
        $this->assertArrayHasKey('per_page', $responseData);
    }

    public function testRolesShow(): void
    {
        $rolesShow = $this->rolesShow($this->tenant->role_disposable->id);

        $rolesShow->assertOk();
        $rolesShow->assertJsonStructure([
            "data" => [
                "name",
                "permissions",
                "created_at",
            ],
        ]);
    }

    public function testRolesUpdate(): void
    {
        $roleUpdate = $this->rolesUpdate(
            $this->tenant->role_disposable->id,
            [
                "name" => "Updated Role Name",
                "permissions" => [
                    "add" => ["user:view", "user:create", "role:view", "role:create"],
                    "remove" => ["profile:view", "profile:update"],
                ],
            ]
        );

        $roleUpdate->assertStatus(200);

        $rolesShow = $this->rolesShow($this->tenant->role_disposable->id);
        $rolesShow->assertOk();

        $this->assertEquals("Updated Role Name", $rolesShow->json()['data']['name']);
        $this->assertEquals(
            ["user:view", "user:create", "role:view", "role:create"],
            $rolesShow->json()['data']['permissions']
        );
    }

    public function testRolesDelete(): void
    {
        $deleteResponse = $this->rolesDelete($this->tenant->role_disposable->id);

        $deleteResponse->assertStatus(200);

        $showResponse = $this->rolesShow($this->tenant->role_disposable->id);
        $showResponse->assertStatus(404);
    }

    public function testRolesRestore(): void
    {
        $restoreResponse = $this->rolesRestore($this->tenant->role_disposable->id);
        $restoreResponse->assertStatus(200);

        $showResponse = $this->rolesShow($this->tenant->role_disposable->id);
        $showResponse->assertOk();
    }

    public function testRolesDeleteFlow(): void
    {
        $deleteResponse = $this->rolesDelete($this->tenant->role_disposable->id);
        $deleteResponse->assertStatus(200);

        $showResponse = $this->rolesShow($this->tenant->role_disposable->id);
        $showResponse->assertStatus(404);

        $rolesListArchived = $this->rolesListArchived();
        $rolesListArchived->assertOk();

        $responseData = $rolesListArchived->json();
        $this->assertEquals(1, $responseData['total']);
        $this->assertArrayHasKey('total', $responseData);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('last_page', $responseData);
        $this->assertArrayHasKey('current_page', $responseData);
        $this->assertArrayHasKey('per_page', $responseData);

        $rolesListArchived = $this->forceDelete(
            $this->tenant->role_disposable->id);
        $rolesListArchived->assertOk();

        $rolesListArchived = $this->rolesListArchived();
        $responseData = $rolesListArchived->json();
        $this->assertEquals(0, $responseData['total']);

        $rolesList = $this->rolesList();
        $responseData = $rolesList->json();
        $this->assertEquals(4, $responseData['total']);
    }

    protected function rolesList(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "{$this->base_tenant_api_admin}roles",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesListArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "{$this->base_tenant_api_admin}roles/?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesShow(string $roleId): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "{$this->base_tenant_api_admin}roles/$roleId",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}roles",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function rolesUpdate(string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_tenant_api_admin}roles/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function rolesDelete(string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_tenant_api_admin}roles/$roleId",
            data: [
                "background_role_id" => $this->tenant->role_visitor->id,
            ],
            headers: $this->getHeaders(),
        );
    }

    protected function forceDelete(string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_tenant_api_admin}roles/$roleId/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesRestore(string $roleId): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}roles/$roleId/restore",
            headers: $this->getHeaders(),
        );
    }
}
