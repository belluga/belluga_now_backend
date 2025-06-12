<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAdminRolesPermissionsTest extends TestCaseAuthenticated
{

    public function testListWithPermission(): void {}

    public function testListNoPermission(): void {}

    public function testListWithPermissionNoTenant(): void {}

    public function testCreateWithPermission(): void {}

    public function testCreateNoPermission(): void {}

    public function testCreateWithPermissionNoTenant(): void {}

    public function testShowWithPermission(): void {}

    public function testShowNoPermission(): void {}

    public function testShowWithPermissionNoTenant(): void {}

    public function testUpdateWithPermission(): void {}

    public function testUpdateNoPermission(): void {}

    public function testUpdateWithPermissionNoTenant(): void {}

    public function testDeleteWithPermission(): void {}

    public function testDeleteNoPermission(): void {}

    public function testDeleteWithNoTenant(): void {}

    public function testRestoreWithPermission(): void {}

    public function testRestoreNoPermission(): void {}

    public function testRestoreWithPermissionNoTenant(): void {}

    public function testDeleteFlowWithPermission(): void {}

    public function testDeleteFlowNoPermission(): void {}

    public function testDeleteFlowWithPermissionNoTenant(): void {}

    protected function list(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/roles",
            headers: $this->getHeaders(),
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

    protected function create(array $data): TestResponse
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

    protected function deleteItem(string $roleId): TestResponse
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

    protected function restore(string $roleId): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/roles/$roleId/restore",
            headers: $this->getHeaders(),
        );
    }

}
