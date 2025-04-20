<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\AccountEnvironments;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountsTest extends TestCaseAuthenticated {

    protected string $secondary_account_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_ACCOUNT_ID->value, $value);
            $this->secondary_account_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_ID->value);
        }
    }

    protected string $secondary_account_slug {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_ACCOUNT_SLUG->value, $value);
            $this->secondary_account_slug = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_SLUG->value);
        }
    }

    protected string $secondary_account_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_ACCOUNT_TOKEN->value, $value);
            $this->secondary_account_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_TOKEN->value);
        }
    }

    protected string $secondary_user_password {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_USER_PASSWORD->value, $value);
            $this->secondary_user_password = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_USER_PASSWORD->value);
        }
    }

    protected string $secondary_user_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_USER_ID->value, $value);
            $this->secondary_user_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_USER_ID->value);
        }
    }
    protected string $user_password {
        get {
            return $this->getGlobal(TestVariableLabels::USER_PASSWORD->value);
        }
    }

    protected string $main_user_id {
        get {
            return $this->getGlobal(TestVariableLabels::MAIN_USER_ID->value);
        }
    }

    protected string $main_account_slug {
        get {
            return $this->getGlobal(TestVariableLabels::MAIN_ACCOUNT_SLUG->value);
        }
    }

    protected string $main_account_id {
        get {
            return $this->getGlobal(TestVariableLabels::MAIN_ACCOUNT_ID->value);
        }
    }

    public function testAccountUserCreation(): void {
        $response = $this->createUser();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "success",
            "data" => [
                "user" => [
                    "name",
                    "email",
                    "account_ids",
                    "updated_at",
                    "created_at",
                    "id"
                ]
            ]
        ]);
    }

    public function testAccountUsersList(): void {
        $response = $this->listUsers();

        $response->assertStatus(200);

        $response->assertJsonStructure([
            "current_page",
            "data",
            "first_page_url",
            "from",
            "last_page",
            "last_page_url",
            "links",
            "next_page_url",
            "path",
            "per_page",
            "prev_page_url",
            "to",
            "total"
        ]);

        $responseData = $response->json();
        $this->assertCount(2, $responseData['data']);
    }

    public function testAccountCreation(): void {
        $response = $this->createAccount();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "success",
            "data" => [
                "account" => [
                    "name",
                    "document",
                    "address",
                    "slug",
                    "id",
                    "updated_at",
                    "created_at"
                ],
                'token'
            ]
        ]);

        $this->secondary_account_id = $response->json()['data']['account']["id"];
        $this->secondary_account_slug = $response->json()['data']['account']["slug"];
        $this->secondary_account_token = $response->json()['data']['token'];
    }

    public function testAccountList(): void {
        $response = $this->listAccounts();

        $response->assertStatus(200);

        $response->assertJsonStructure([
            "current_page",
            "data",
            "first_page_url",
            "from",
            "last_page",
            "last_page_url",
            "links",
            "next_page_url",
            "path",
            "per_page",
            "prev_page_url",
            "to",
            "total"
        ]);

        $responseData = $response->json();
        $this->assertCount(2, $responseData['data']);
    }

    public function testCreateUserAnotherAccount(): void {
        $response = $this->createUserNewAccount();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "success",
            "data" => [
                "user" => [
                    "name",
                    "email",
                    "account_ids",
                    "updated_at",
                    "created_at",
                    "id"
                ]
            ]
        ]);

        $responseData = $response->json();

        $this->assertIsArray($responseData['data']['user']['account_ids']);
        $this->assertContains($this->secondary_account_id, $responseData['data']['user']['account_ids']);

        $this->secondary_user_id = $responseData['data']['user']['id'];
    }

    public function testAddOldUserToNewAccountWrongToken(): void {
        $response = $this->addOldUserToNewAccountWrongToken();

        $response->assertStatus(403);
    }

    public function testAddOldUserToNewAccount(): void {
        $response = $this->addOldUserToNewAccount();

        $response->assertStatus(201);
    }

    public function testListAccountsFromUser(): void {
        $response = $this->listMainUserAccounts();

        $response->assertStatus(200);

        $responseData = $response->json();
        $this->assertCount(2, $responseData['data']);
        $accountIds = array_column($responseData['data'], 'id');
        $this->assertContains($this->main_account_id, $accountIds);
        $this->assertContains($this->secondary_account_id, $accountIds);

        $response = $this->listSecondaryUserAccounts();

        $response->assertStatus(200);

        $responseData = $response->json();
        $this->assertCount(1, $responseData['data']);
        $accountIds = array_column($responseData['data'], 'id');
        $this->assertContains($this->secondary_account_id, $accountIds);
    }

    public function testAccountTokenCreation(): void {
        $response = $this->createToken();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "success",
            "token",
        ]);
    }

    public function testErrorAccountTokenCreationWithWrongCredentials(): void {
        $response = $this->createTokenWrongCredentials();

        $response->assertStatus(403);
    }

    public function testErrorAccountUserCreationOnAccountUserDontBelongTo(): void {
        $response = $this->createTokenOnAccountUserDontBelongs();

        $response->assertStatus(403);
    }

    public function testErrorAccountUserCreationAccountNotExists(): void {
        $response = $this->createUserWrongAccount();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            'success',
            'data' => [
                'name',
                'email',
                'password',
                'account_id'
            ],
            'errors' => [
                'account_id'
            ]
        ]);

        $response->assertJsonPath('errors.account_id.0', 'Account not found');
    }

    protected function createAccount(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/accounts",
            data: $this->payloadAccountCreation(),
            headers: $this->getHeaders(),
        );
    }

    protected function listAccounts(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "api/accounts",
            headers: $this->getHeaders(),
        );
    }

    protected function listMainUserAccounts(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "api/users/$this->main_user_id/accounts",
            headers: $this->getHeaders(),
        );
    }

    protected function listSecondaryUserAccounts(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "api/users/$this->secondary_user_id/accounts",
            headers: $this->getHeaders(AccountEnvironments::SECONDARY),
        );
    }

    protected function createUser(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/users",
            data: $this->payloadUserCreation(),
            headers: $this->getHeaders(),
        );
    }

    protected function createUserNewAccount(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/users",
            data: $this->payloadUserCreationNewAccount(),
            headers: $this->getHeaders(),
        );
    }

    protected function addOldUserToNewAccountWrongToken(): TestResponse {
        return $this->json(
            method: 'put',
            uri: "api/accounts/$this->secondary_account_slug/users",
            data: $this->payloadAddUserToAccount(),
            headers: $this->getHeaders(),
        );
    }

    protected function addOldUserToNewAccount(): TestResponse {
        return $this->json(
            method: 'put',
            uri: "api/accounts/$this->secondary_account_slug/users",
            data: $this->payloadAddUserToAccount(),
            headers: $this->getHeaders(AccountEnvironments::SECONDARY),
        );
    }

    protected function createUserWrongAccount(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/users",
            data: $this->payloadUserCreationWrongAccount(),
            headers: $this->getHeaders(),
        );
    }

    protected function listUsers(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "api/accounts/$this->main_account_slug/users",
            headers: $this->getHeaders(),
        );
    }

    protected function createToken(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/accounts/$this->main_account_slug/token",
            data: $this->payloadTokenCreation(),
        );
    }

    protected function createTokenWrongCredentials(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/accounts/$this->secondary_account_slug/token",
            data: $this->payloadTokenCreationWrongCredentials(),
        );
    }

    protected function createTokenOnAccountUserDontBelongs(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/accounts/$this->main_account_slug/token",
            data: $this->payloadTokenCreationUserDontBelongTo(),
        );
    }

    protected function payloadUserCreation(): array {
        return [
            "name" => fake()->name(),
            "email" => fake()->email(),
            "password" => fake()->password(),
            "account_id" => $this->main_account_id,
        ];
    }

    protected function payloadUserCreationNewAccount(): array {

        $this->secondary_user_password = fake()->password();

        return [
            "name" => fake()->name(),
            "email" => fake()->email(),
            "password" => $this->secondary_user_password,
            "account_id" => $this->secondary_account_id,
        ];
    }

    protected function payloadAddUserToAccount(): array {
        return [
            "user_id" => $this->main_user_id,
        ];
    }

    protected function payloadUserCreationWrongAccount(): array {
        return [
            "name" => fake()->name(),
            "email" => fake()->email(),
            "password" => fake()->password(),
            "account_id" => "68026cefa535d61928023494"
        ];
    }

    protected function payloadUserCreationWrongCredentials(): array {
        return [
            "name" => fake()->name(),
            "email" => fake()->email(),
            "password" => $this->secondary_user_password,
            "account_id" => "68026cefa535d61928023494"
        ];
    }

    protected function payloadTokenCreation(): array {
        return [
            "token_name" => "Token Test",
            "user_id" => $this->main_user_id,
            "password" => $this->user_password,
        ];
    }

    protected function payloadTokenCreationWrongCredentials(): array {
        return [
            "token_name" => "Token Test",
            "user_id" => $this->main_user_id,
            "password" => "123",
        ];
    }

    protected function payloadTokenCreationUserDontBelongTo(): array {
        return [
            "token_name" => "Token Test",
            "user_id" => $this->secondary_user_id,
            "password" => $this->secondary_user_password,
        ];
    }

    protected function payloadAccountCreation(): array {
        return [
            "name" => fake()->company(),
            "document" => fake()->cnpj(false),
            "address" => fake()->address(),
        ];
    }

}
