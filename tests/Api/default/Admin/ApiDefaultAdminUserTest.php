<?php

namespace Tests\Api\default\Admin;

use Illuminate\Testing\TestResponse;
use Tests\TestCaseAuthenticated;

class ApiDefaultAdminUserTest extends TestCaseAuthenticated {

    public function testUserTenantsManagerCreate(): void {

        $this->landlord->user_cross_tenant_admin->name = fake()->name();
        $this->landlord->user_cross_tenant_admin->email_1 = fake()->email();
        $this->landlord->user_cross_tenant_admin->email_2 = fake()->email();
        $this->landlord->user_cross_tenant_admin->password = fake()->password(8);

        $response = $this->userCreate([
            "name" => $this->landlord->user_cross_tenant_admin->name,
            "emails" => [
                $this->landlord->user_cross_tenant_admin->email_1,
                $this->landlord->user_cross_tenant_admin->email_2,
            ],
            "password" => $this->landlord->user_cross_tenant_admin->password,
            "password_confirmation" => $this->landlord->user_cross_tenant_admin->password,
            "device_name" => "test",
            "role_id" => $this->landlord->role_tenants_manager->id,
        ]);

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "message",
            "data" => [
                "name",
                "id",
            ]

        ]);

        $this->landlord->user_cross_tenant_admin->user_id = $response->json()['data']["id"];
    }

    public function testUserCreateAgain(): void {

        $response = $this->userCreate([
            "name" => fake()->name,
            "emails" => [
                $this->landlord->user_cross_tenant_admin->email_1,
                $this->landlord->user_cross_tenant_admin->email_2,
            ],
            "password" => $this->landlord->user_cross_tenant_admin->password,
            "password_confirmation" => $this->landlord->user_cross_tenant_admin->password,
            "device_name" => "test",
            "role_id" => $this->landlord->role_tenants_manager->id,
        ]);

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "emails"
            ],
        ]);
    }

    public function testUserVisitorCreate(): void {

        $this->landlord->user_cross_tenant_visitor->name = fake()->name();
        $this->landlord->user_cross_tenant_visitor->email_1 = fake()->email();
        $this->landlord->user_cross_tenant_visitor->email_2 = fake()->email();
        $this->landlord->user_cross_tenant_visitor->password = fake()->password(8);

        $response = $this->userCreate([
            "name" => $this->landlord->user_cross_tenant_visitor->name,
            "emails" => [
                $this->landlord->user_cross_tenant_visitor->email_1,
                $this->landlord->user_cross_tenant_visitor->email_2,
            ],
            "password" => $this->landlord->user_cross_tenant_visitor->password,
            "password_confirmation" => $this->landlord->user_cross_tenant_visitor->password,
            "device_name" => "test",
            "role_id" => $this->landlord->role_visitor->id,
        ]);

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "message",
            "data" => [
                "name",
                "id",
            ]

        ]);

        $this->landlord->user_cross_tenant_visitor->user_id = $response->json()['data']["id"];
    }

    public function testUserDisposableCreate(): void {

        $this->landlord->user_disposable->name = fake()->name();
        $this->landlord->user_disposable->email_1 = fake()->email();
        $this->landlord->user_disposable->email_2 = fake()->email();
        $this->landlord->user_disposable->password = fake()->password(8);

        $response = $this->userCreate([
            "name" => $this->landlord->user_disposable->name,
            "emails" => [
                $this->landlord->user_disposable->email_1,
                $this->landlord->user_disposable->email_2,
            ],
            "password" => $this->landlord->user_disposable->password,
            "password_confirmation" => $this->landlord->user_disposable->password,
            "device_name" => "test",
            "role_id" => $this->landlord->role_visitor->id,
        ]);

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "message",
            "data" => [
                "name",
                "id",
            ]

        ]);

        $this->landlord->user_disposable->user_id = $response->json()['data']["id"];
    }

    public function testUserList(): void {

        $response = $this->userListUnauthenticated();

        $response->assertStatus(401);

        $response->assertJsonStructure([
            "message"
        ]);

        $response = $this->userList();

        $response->assertStatus(200);

        $response_data = $response->json();
        $this->assertEquals(4, count($response_data['data']));
        $this->assertArrayHasKey('current_page', $response_data);
        $this->assertArrayHasKey('per_page', $response_data);
    }

    public function testSoftDelete(): void
    {
        $response = $this->userSoftDelete($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

    }

    public function testListArchived(): void {
        $response = $this->userList();
        $this->assertEquals(3, count($response['data']));

        $response = $this->userListArchived();
        $this->assertEquals(1, count($response['data']));
    }

    public function testUserRestore(): void {
        $response = $this->userRestore($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $response = $this->userList();
        $this->assertEquals(4, count($response['data']));
    }

    public function testUserShow(): void {
        $response = $this->userShow($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            "data" => [
                "emails",
                "name",
                "id",
                "created_at",
            ],
        ]);
    }

    public function testUserUpdate(): void {
        $new_name = fake()->name();

        $response = $this->userUpdate(
            $this->landlord->user_disposable->user_id,
            [
                "name" => $new_name,
            ]
        );

        $response->assertStatus(200);

        $response = $this->userShow($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $this->assertEquals(
            $new_name, $response->json()['data']['name']
        );
    }

    public function testUserDeleteFlow(): void {
        $response = $this->userList();
        $this->assertEquals(4, count($response['data']));

        $response = $this->userSoftDelete($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $response = $this->userList();
        $this->assertEquals(3, count($response['data']));

        $response = $this->userListArchived();
        $this->assertEquals(1, count($response['data']));

        $response = $this->userForceDelete($this->landlord->user_disposable->user_id);
        $response->assertStatus(200);

        $response = $this->userList();
        $this->assertEquals(3, count($response['data']));

        $response = $this->userListArchived();
        $this->assertEquals(0, count($response['data']));
    }

    protected function userUpdate(string $user_id, array $data): TestResponse {

        return $this->json(
            method: 'patch',
            uri: "admin/api/users/$user_id",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userShow(string $user_id): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function userRestore(string $user_id): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/users/$user_id/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function userSoftDelete(string $user_id): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "admin/api/users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function userForceDelete(string $user_id): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "admin/api/users/$user_id/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function userListUnauthenticated(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/users",
        );
    }

    protected function userList(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/users",
            headers: $this->getHeaders(),
        );
    }

    protected function userListArchived(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/users?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function userCreate(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }
}
