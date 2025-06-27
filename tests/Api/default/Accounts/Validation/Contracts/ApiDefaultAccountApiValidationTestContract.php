<?php

namespace Tests\Api\default\Accounts\Validation\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Api\default\Accounts\Contracts\TestCaseAccount;

abstract class ApiDefaultAccountApiValidationTestContract extends TestCaseAccount
{

    protected string $base_api_url {
        get{
            return $this->base_api;
        }
    }

    public function testAccountRolesCreate(): void
    {

        $response = $this->accountRolesCreate();

        $response->assertStatus(422);
        $response->assertJsonStructure([
            "message",
            "errors" => [
                "name",
                "permissions"
            ]
        ]);

    }

    public function testAccountRolesUpdate(): void
    {

        $response = $this->accountRolesUpdate($this->account->role_visitor->id);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            "message",
            "errors" => [
                "empty",
            ]
        ]);
    }

    public function testAccountRolesDelete(): void
    {
        $deleteResponse = $this->accountRolesDelete(
            $this->account->role_visitor->id,
            []
        );
        $deleteResponse->assertStatus(422);

        $deleteResponse->assertJsonStructure([
           "message",
           "errors" => [
               "role_id"
           ]
        ]);
    }

    public function testAccountUserCreate(): void {

        $response = $this->userCreate([]);
        $response->assertStatus(422);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "name",
                "emails",
                "password",
                "role_id"
            ],
        ]);
    }

    public function testAccountUserEmailRemove(): void {

        $response = $this->userEmailRemove(
            $this->account->user_visitor->user_id,
            []);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            "message",
            "errors" => [
                "email"
            ]
        ]);

        $response = $this->userEmailRemove(
            $this->account->user_visitor->user_id,
            [
                "email" => $this->account->user_visitor->email_2
            ]);

        $response->assertStatus(200);

        $response = $this->userEmailRemove(
            $this->account->user_visitor->user_id,
            [
                "email" => $this->account->user_visitor->email_1
            ]);

        $response->assertStatus(422);
        $this->assertEquals(
            "Você não pode remover o único email da conta. Adicione outro email antes de remover esse.",
            $response->json()['message']);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "email"
            ]
        ]);
    }

    public function testAccountUserEmailAddRepeated(): void {
        $response = $this->userEmailAdd(
            $this->account->user_visitor->user_id,
            [
                "emails" => [
                    $this->account->user_users_manager->email_1
                ]
            ]);
        $response->assertStatus(422);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "emails",
            ],
        ]);
    }

    public function testAccountUserEmailAddEmpty(): void {

        $response = $this->userEmailAdd(
            $this->account->user_visitor->user_id,
            []);
        $response->assertStatus(422);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "emails",
            ],
        ]);

        $response = $this->userEmailAdd(
            $this->account->user_visitor->user_id,
            [
                "emails" => [
                    $this->account->user_visitor->email_1,
                    $this->account->user_visitor->email_2,
                ]
            ]);
        $response->assertStatus(200);
    }

    public function testAccountUserUpdate(): void {

        $response = $this->userUpdate(
            $this->account->user_visitor->user_id,
            []);
        $response->assertStatus(422);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "empty",
            ],
        ]);
    }

    protected function accountRolesCreate(): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}roles",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesUpdate(string $roleId): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_url}roles/$roleId",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesDelete(string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}roles/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userCreate(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userUpdate(string $user_id, array $data): TestResponse {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_url}users/$user_id",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userDelete(array $data): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userEmailAdd(string $user_id, array $data): TestResponse {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_url}users/$user_id/emails",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function userEmailRemove(string $user_id, array $data): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}users/$user_id/emails",
            data: $data,
            headers: $this->getHeaders(),
        );
    }
}
