<?php

namespace Tests\Api\default;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultAdminUserTest extends TestCaseAuthenticated {
    protected string $secondary_user_password {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_PASSWORD->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_PASSWORD->value, fake()->password(8));
            }
            return $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_PASSWORD->value);
        }
    }

    protected string $secondary_user_email {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_EMAIL->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_EMAIL->value, fake()->email());
            }
            return $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_EMAIL->value);
        }
    }


    protected string $secondary_landlord_user_id {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_ID->value);
        }
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_ID->value, $value);
            $this->secondary_landlord_user_id = $value;
        }
    }

    public function testUserCreate(): void {
        $response = $this->userCreate($this->payloadUserCreate());

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "message",
            "data" => [
                "name",
                "id",
            ],
        ]);

        $this->secondary_landlord_user_id = $response->json()['data']["id"];
    }

    public function testUserCreateAgain(): void {
        $response = $this->userCreate($this->payloadUserCreate());

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "emails"
            ],
        ]);
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
        $this->assertEquals(2, count($response_data['data']));
        $this->assertArrayHasKey('current_page', $response_data);
        $this->assertArrayHasKey('per_page', $response_data);
    }

    public function testSoftDelete(): void
    {
        $response = $this->userSoftDelete($this->secondary_landlord_user_id);
        $response->assertStatus(200);

    }

    public function testListArchived(): void {
        $response = $this->userList();
        $this->assertEquals(1, count($response['data']));

        $response = $this->userListArchived();
        $this->assertEquals(1, count($response['data']));
    }

    public function testUserRestore(): void {
        $response = $this->userRestore();
        $response->assertStatus(200);

        $response = $this->userList();
        $this->assertEquals(2, count($response['data']));
    }

    public function testUserShow(): void {
        $response = $this->userShow();
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
            [
                "name" => $new_name,
            ]
        );

        $response->assertStatus(200);

        $response = $this->userShow();
        $response->assertStatus(200);

        $this->assertEquals(
            $new_name, $response->json()['data']['name']
        );
    }

    public function testUserDeleteFlow(): void {
        $response = $this->userCreate($this->payloadUserToDelete());
        $response->assertStatus(201);

        $user_id = $response->json()['data']['id'];

        $response = $this->userList();
        $this->assertEquals(3, count($response['data']));

        $response = $this->userSoftDelete($user_id);
        $response->assertStatus(200);

        $response = $this->userList();
        $this->assertEquals(2, count($response['data']));

        $response = $this->userListArchived();
        $this->assertEquals(1, count($response['data']));

        $response = $this->userForceDelete($user_id);
        $response->assertStatus(200);

        $response = $this->userList();
        $this->assertEquals(2, count($response['data']));

        $response = $this->userListArchived();
        $this->assertEquals(0, count($response['data']));
    }

    protected function userUpdate(array $data): TestResponse {

        return $this->json(
            method: 'patch',
            uri: "admin/api/users/$this->secondary_landlord_user_id",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userShow(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/users/$this->secondary_landlord_user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function userRestore(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/users/$this->secondary_landlord_user_id/restore",
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

    protected function payloadUserCreate(): array {
        return [
            "name" => fake()->name(),
            "emails" => [
                $this->secondary_user_email,
            ],
            "password" => $this->secondary_user_password,
            "password_confirmation" => $this->secondary_user_password,
            "device_name" => "test"

        ];
    }

    protected function payloadUserToDelete(): array {
        $password = fake()->password(8);
        return [
            "name" => fake()->name(),
            "emails" => [
                fake()->email(),
            ],
            "password" => $password,
            "password_confirmation" => $password,
            "device_name" => "test"

        ];
    }
}
