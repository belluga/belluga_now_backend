<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultValidationTest extends TestCaseAuthenticated {

    protected string $main_account_slug {
        get {
            return $this->getGlobal(TestVariableLabels::MAIN_ACCOUNT_SLUG->value);
        }
    }

    public function testInitialization(): void {
        $response = $this->initiate();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "success",
            "errors" => [
                "email",
                "password",
                "account.name",
                "account.document",
                "account.address",
            ]
        ]);
    }

    public function testUserCreation(): void {
        $response = $this->userCreate();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "success",
            "errors" => [
                "email",
                "password",
                "account_id",
            ]
        ]);
    }

    public function testAccountCreation(): void {
        $response = $this->accountCreate();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "success",
            "errors" => [
                "name",
                "document",
                "address",
            ]
        ]);
    }

    public function testAccountTokenCreation(): void {
        $response = $this->accountTokenCreate();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "success",
            "errors" => [
                "user_id",
                "password",
                "token_name",
            ]
        ]);
    }

    protected function initiate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/initialize"
        );
    }

    protected function userCreate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/users",
            headers: $this->getHeaders()
        );
    }

    protected function accountCreate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/accounts",
            headers: $this->getHeaders()
        );
    }

    protected function accountTokenCreate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/accounts/$this->main_account_slug/token",
            headers: $this->getHeaders()
        );
    }

}
