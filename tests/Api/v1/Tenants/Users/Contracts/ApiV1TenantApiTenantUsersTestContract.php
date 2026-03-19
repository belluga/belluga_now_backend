<?php

namespace Tests\Api\v1\Tenants\Users\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\RoleLabels;
use Tests\Helpers\UserLabels;
use Tests\TestCaseTenant;

abstract class ApiV1TenantApiTenantUsersTestContract extends TestCaseTenant
{
    public function testUsersCreateAndAttachAdmin(): void
    {
        $this->userCreate(
            $this->tenant->user_admin,
            $this->landlord->role_visitor);

        $this->userAttachTenant(
            $this->tenant->user_admin,
            $this->tenant->role_admin);
    }

    public function testUsersCreateAndAttachUsersManager(): void
    {
        $this->userCreate(
            $this->tenant->user_users_manager,
            $this->landlord->role_visitor);

        $this->userAttachTenant(
            $this->tenant->user_users_manager,
            $this->tenant->role_users_manager);
    }

    public function testUsersCreateAndAttachRolesManager(): void
    {
        $this->userCreate(
            $this->tenant->user_roles_manager,
            $this->landlord->role_visitor);

        $this->userAttachTenant(
            $this->tenant->user_roles_manager,
            $this->tenant->role_roles_manager);
    }

    public function testUsersCreateAndAttachVisitor(): void
    {

        $this->userCreate(
            $this->tenant->user_visitor,
            $this->landlord->role_visitor);

        $this->userAttachTenant(
            $this->tenant->user_visitor,
            $this->tenant->role_roles_manager);
    }

    public function testAttachLandlordUsers(): void
    {

        $this->userAttachTenant(
            $this->landlord->user_cross_tenant_admin,
            $this->tenant->role_admin,
        );

        $this->userAttachTenant(
            $this->landlord->user_cross_tenant_visitor,
            $this->tenant->role_visitor,
        );
    }

    public function testUserDettachAccount(): void
    {
        $response = $this->tenantUserDettach([
            'user_id' => $this->tenant->user_visitor->user_id,
            'role_id' => $this->tenant->role_roles_manager->id,
        ]);
        $response->assertStatus(200);

        $responseShow = $this->tenantUserShow($this->tenant->user_visitor->user_id);

        $responseShow->assertStatus(200);
        $responseShow->assertJsonStructure([
            'data' => [
                'tenant_roles',
            ],
        ]);

        $this->assertEquals(0, count($responseShow->json()['data']['tenant_roles']));
    }

    public function userAttachTenant(UserLabels $user, RoleLabels $role): void
    {

        $response = $this->tenantUserAttach([
            'user_id' => $user->user_id,
            'role_id' => $role->id,
        ]);

        $response->assertStatus(200);

        $responseShow = $this->tenantUserShow($user->user_id);

        $responseShow->assertStatus(200);
        $responseShow->assertJsonStructure([
            'data' => [
                'tenant_roles' => [
                    '*' => [
                        'slug',
                        'tenant_id',
                    ],
                ],
            ],
        ]);
    }

    public function userCreate(UserLabels $user, RoleLabels $role): void
    {
        $user->name = fake()->name();
        $user->email_1 = fake()->email();
        $user->email_2 = fake()->email();
        $user->password = fake()->password(8);

        $response = $this->_userCreate([
            'name' => $user->name,
            'email' => $user->email_1,
            'password' => $user->password,
            'password_confirmation' => $user->password,
            'device_name' => 'test',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(201);

        $response->assertJsonStructure([
            'message',
            'data' => [
                'name',
                'id',
            ],

        ]);

        $user->user_id = $response->json()['data']['id'];
    }

    protected function _userCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->host}/admin/api/v1/users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUserShow(string $user_id): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->host}/admin/api/v1/users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUserAttach(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "{$this->base_tenant_api_admin}tenant-users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUserDettach(array $data): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_tenant_api_admin}tenant-users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }
}
