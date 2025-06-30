<?php

namespace Tests\Api\default\Accounts\Validation\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Api\Traits\AccountProfileFunctions;
use Tests\TestCaseAccount;

abstract class ApiDefaultAccountApiValidationTestContract extends TestCaseAccount
{

    use AccountProfileFunctions;

    protected string $base_api_url {
        get{
            return $this->base_api_account;
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

        $response = $this->profileRemoveEmail(
            $this->account->user_visitor,
            "");

        $response->assertStatus(422);
        $response->assertJsonStructure([
            "message",
            "errors" => [
                "email"
            ]
        ]);
    }

    public function testAccountUserEmailAddEmpty(): void {

        $response = $this->profileAddEmails(
            $this->account->user_visitor,
            []);
        $response->assertStatus(422);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "emails",
            ],
        ]);

        $response = $this->profileAddEmails(
            $this->account->user_visitor,
            [
                $this->account->user_visitor->email_1,
                $this->account->user_visitor->email_2,
            ]);
        $response->assertStatus(200);
    }

    public function testAccountUserUpdate(): void {

        $response = $this->profileUpdate(
            $this->account->user_visitor,
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

    protected function userDelete(array $data): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }
}
