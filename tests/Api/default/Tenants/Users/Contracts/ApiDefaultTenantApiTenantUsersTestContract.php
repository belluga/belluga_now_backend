<?php

namespace Tests\Api\default\Tenants\Users\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Api\default\Tenants\Contracts\TestCaseTenant;
use Tests\Helpers\RoleLabels;
use Tests\Helpers\UserLabels;

abstract class ApiDefaultTenantApiTenantUsersTestContract extends TestCaseTenant {

    public function testUsersCreateAndAttachAdmin(): void {

        print("testUsersCreateAndAttachAdmin > ");

        $this->userCreate(
            $this->tenant->user_admin,
            $this->landlord->role_visitor);

        $this->userAttachTenant(
            $this->tenant->user_admin,
            $this->tenant->role_admin);

        print_r([
            "user" => [
                "name" => $this->tenant->user_admin->name,
                "user_id" => $this->tenant->user_admin->user_id
            ],
            "role" => [
                "name" => $this->tenant->role_admin->name,
                "id" => $this->tenant->role_admin->id
            ]
        ]);
    }

    public function testUsersCreateAndAttachUsersManager(): void {
        $this->userCreate(
            $this->tenant->user_users_manager,
            $this->landlord->role_visitor);

        $this->userAttachTenant(
            $this->tenant->user_users_manager,
            $this->tenant->role_users_manager);
    }

    public function testUsersCreateAndAttachRolesManager(): void {
        $this->userCreate(
            $this->tenant->user_roles_manager,
            $this->landlord->role_visitor);

        $this->userAttachTenant(
            $this->tenant->user_roles_manager,
            $this->tenant->role_roles_manager);
    }

    public function testUsersCreateAndAttachVisitor(): void {

        print("testUsersCreateAndAttachVisitor > ");
        $this->userCreate(
            $this->tenant->user_visitor,
            $this->landlord->role_visitor);

        $this->userAttachTenant(
            $this->tenant->user_visitor,
            $this->tenant->role_roles_manager);

        print_r([
            "user" => [
                "name" => $this->tenant->user_visitor->name,
                "user_id" => $this->tenant->user_visitor->user_id,
            ],
            "role" => [
                "name" => $this->tenant->role_visitor->name,
                "id" => $this->tenant->role_visitor->id
            ]

        ]);
    }

    public function testAttachLandlordUsers(): void {

        $this->userAttachTenant(
            $this->landlord->user_cross_tenant_admin,
            $this->tenant->role_admin,
        );

        $this->userAttachTenant(
            $this->landlord->user_cross_tenant_visitor,
            $this->tenant->role_visitor,
        );
    }

    public function testUserDettachAccount(): void {
        $response = $this->tenantUserDettach([
            "user_id" => $this->tenant->user_visitor->user_id,
            "role_id" => $this->tenant->role_roles_manager->id,
        ]);
        $response->assertStatus(200);


        $responseShow = $this->tenantUserShow($this->tenant->user_visitor->user_id);

        print_r($responseShow->json());

        $responseShow->assertStatus(200);
        $responseShow->assertJsonStructure([
            "data" => [
                "tenant_roles"
            ]
        ]);

        $this->assertEquals(0, count($responseShow->json()['data']['tenant_roles']));
    }

    public function userAttachTenant(UserLabels $user, RoleLabels $role): void {

        $response = $this->tenantUserAttach([
            "user_id" => $user->user_id,
            "role_id" => $role->id,
        ]);

        print_r($response->json());

        $response->assertStatus(200);

        $responseShow = $this->tenantUserShow($user->user_id);

        $responseShow->assertStatus(200);
        $responseShow->assertJsonStructure([
            "data" => [
                "tenant_roles" => [
                    "*" => [
                        "slug",
                        "tenant_id",
                    ]
                ]
            ]
        ]);
    }

    public function userCreate(UserLabels $user, RoleLabels $role): void {
        $user->name = fake()->name();
        $user->email_1 = fake()->email();
        $user->email_2 = fake()->email();
        $user->password = fake()->password(8);

        $response = $this->_userCreate([
            "name" => $user->name,
            "emails" => [
                $user->email_1,
                $user->email_2,
            ],
            "password" => $user->password,
            "password_confirmation" => $user->password,
            "device_name" => "test",
            "role_id" => $role->id,
        ]);

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "message",
            "data" => [
                "name",
                "id",
            ]

        ]);

        $user->user_id = $response->json()['data']["id"];
    }

    protected function _userCreate(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUserShow(string $user_id): TestResponse {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant->subdomain}.localhost/api/tenant-users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUserAttach(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant->subdomain}.localhost/api/tenant-users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUserDettach(array $data): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant->subdomain}.localhost/api/tenant-users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

}
