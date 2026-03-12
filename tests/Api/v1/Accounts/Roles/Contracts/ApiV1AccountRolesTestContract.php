<?php

namespace Tests\Api\v1\Accounts\Roles\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\RoleLabels;
use Tests\TestCaseAccount;

abstract class ApiV1AccountRolesTestContract extends TestCaseAccount
{
    protected string $base_api_url {
        get{
            return $this->base_api_account.'roles';
        }
    }

    public function testAccountRolesList(): void
    {
        $rolesList = $this->accountRolesList();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(0, $responseData['total']);
        $this->assertArrayHasKey('data', $responseData);

        $rolesList = $this->accountRolesList();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(0, $responseData['total']);
        $this->assertArrayHasKey('data', $responseData);
    }

    public function testAccountRolesCreate(): void
    {
        $this->account->role_manager->name = 'Role Manager';

        $response = $this->accountRolesCreate(
            [
                'name' => $this->account->role_manager->name,
                'description' => 'Role for account editing',
                'permissions' => ['account-users:view', 'account-users:create'],
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'name',
                'description',
                'permissions',
                'account_id',
                'created_at',
            ],
        ]);

        $this->account->role_manager->id = $response->json()['data']['id'];

        $this->account->role_user_manager->name = 'Users Manager';
        $response = $this->accountRolesCreate(
            [
                'name' => $this->account->role_user_manager->name,
                'description' => 'Role for account editing',
                'permissions' => ['account-users:view', 'account-users:create'],
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'name',
                'description',
                'permissions',
                'account_id',
                'created_at',
            ],
        ]);

        $this->account->role_user_manager->id = $response->json()['data']['id'];

        $this->account->role_visitor->name = 'Visitor';
        $response = $this->accountRolesCreate(
            [
                'name' => $this->account->role_visitor->name,
                'description' => 'Role for account editing',
                'permissions' => ['account-users:view', 'account-users:create'],
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'name',
                'description',
                'permissions',
                'account_id',
                'created_at',
            ],
        ]);

        $this->account->role_visitor->id = $response->json()['data']['id'];

        $this->account->role_disposable->name = 'Disposable';
        $response = $this->accountRolesCreate(
            [
                'name' => $this->account->role_disposable->name,
                'description' => 'Role for account editing',
                'permissions' => ['account-users:view', 'account-users:create'],
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'name',
                'description',
                'permissions',
                'account_id',
                'created_at',
            ],
        ]);

        $this->account->role_disposable->id = $response->json()['data']['id'];

    }

    public function testAccountRolesShow(): void
    {
        $rolesShow = $this->accountRolesShow($this->account->role_disposable);
        $rolesShow->assertOk();
        $rolesShow->assertJsonStructure([
            'data' => [
                'name',
                'description',
                'permissions',
                'account_id',
                'created_at',
            ],
        ]);
    }

    public function testAccountRolesUpdate(): void
    {
        $roleUpdate = $this->accountRolesUpdate(
            $this->account->role_disposable,
            [
                'name' => "Updated {$this->account->role_disposable->name}",
                'permissions' => [
                    'add' => ['account-users:view', 'account-users:create', 'account-users:update'],
                ],
            ]
        );

        $roleUpdate->assertStatus(200);

        $rolesShow = $this->accountRolesShow($this->account->role_disposable);
        $rolesShow->assertOk();

        $this->assertEquals("Updated {$this->account->role_disposable->name}", $rolesShow->json()['data']['name']);
        $this->assertEquals(
            ['account-users:view', 'account-users:create', 'account-users:update'],
            $rolesShow->json()['data']['permissions']
        );
    }

    public function testAccountRolesDelete(): void
    {

        $rolesList = $this->accountRolesList();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(2, $responseData['total']);

        $deleteResponse = $this->accountRolesDelete(
            $this->account->role_disposable,
            [
                'background_role_id' => $this->account->role_visitor->id,
            ]
        );

        $deleteResponse->assertStatus(200);

        $rolesList = $this->accountRolesList();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(1, $responseData['total']);

        $rolesList = $this->accountRolesListArchived();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(1, $responseData['total']);

        $showDeleted = $this->accountRolesShow($this->account->role_disposable);
        $showDeleted->assertStatus(404);
    }

    public function testAccountRolesRestore(): void
    {
        $restoreResponse = $this->accountRolesRestore($this->account->role_disposable);
        $restoreResponse->assertStatus(200);

        // Should be able to get the restored role
        $showResponse = $this->accountRolesShow($this->account->role_disposable);
        $showResponse->assertOk();

        $rolesList = $this->accountRolesListArchived();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(2, $responseData['total']);
    }

    public function testAccountRolesDeleteFlow(): void
    {
        $responseListWithCreated = $this->accountRolesList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(2, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountRolesListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountRolesDelete(
            $this->account->role_disposable,
            [
                'background_role_id' => $this->account->role_visitor->id,
            ]
        );
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountRolesList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountRolesListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(1, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountRolesForceDelete($this->account->role_disposable,
        );
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountRolesList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountRolesListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);

    }

    protected function accountRolesList(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: $this->base_api_url,
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesListArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "$this->base_api_url?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesShow(RoleLabels $role): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "$this->base_api_url/$role->id",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesCreate(array $data): TestResponse
    {

        return $this->json(
            method: 'post',
            uri: $this->base_api_url,
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesUpdate(RoleLabels $role, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "$this->base_api_url/$role->id/",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesDelete(RoleLabels $role, array $data): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "$this->base_api_url/$role->id/",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesForceDelete(RoleLabels $role): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "$this->base_api_url/$role->id/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesRestore(RoleLabels $role): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "$this->base_api_url/$role->id/restore",
            headers: $this->getHeaders(),
        );
    }
}
