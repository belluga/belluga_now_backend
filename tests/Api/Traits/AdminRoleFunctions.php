<?php

namespace Tests\Api\Traits;

use Illuminate\Testing\TestResponse;

trait AdminRoleFunctions
{
    protected function rolesList(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/v1/roles",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesListArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/v1/roles?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesShow(string $roleId): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/v1/roles/$roleId",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/v1/roles",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function rolesUpdate(string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "admin/api/v1/roles/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function rolesDelete(string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "admin/api/v1/roles/$roleId",
            data: [
                "role_id" => $this->landlord->role_visitor->id,
            ],
            headers: $this->getHeaders(),
        );
    }

    protected function forceDelete(string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "admin/api/v1/roles/$roleId/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function rolesRestore(string $roleId): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/v1/roles/$roleId/restore",
            headers: $this->getHeaders(),
        );
    }
}
