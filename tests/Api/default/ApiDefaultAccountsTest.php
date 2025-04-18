<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\TestCaseAuthenticated;
use Tests\TestVariableLabels;

class ApiDefaultAccountsTest extends TestCaseAuthenticated {
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

    protected string $user_name;

    protected string $account_name;

    protected string $main_account_slug {
        get {
            return $this->getGlobal(TestVariableLabels::MAIN_ACCOUNT_SLUG->value);
        }
    }

    protected string $account_document;

    protected string $account_address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->getGlobalData();
    }

    protected function getGlobalData(): void{
        try {
//            $this->user_name = $this->getGlobal(TestVariableLabels::USER_NAME->value);
//            $this->user_email = $this->getGlobal(TestVariableLabels::USER_EMAIL->value);
//            $this->user_password = $this->getGlobal(TestVariableLabels::USER_PASSWORD->value);
//            $this->account_name = $this->getGlobal(TestVariableLabels::ACCOUNT_NAME->value);
//            $this->account_document = $this->getGlobal(TestVariableLabels::ACCOUNT_DOCUMENT->value);
//            $this->account_address = $this->getGlobal(TestVariableLabels::ACCOUNT_ADDRESS->value);
        }catch (\Exception $e){
//            $this->setGlobal(TestVariableLabels::USER_NAME->value ,fake()->email());
//            $this->setGlobal(TestVariableLabels::USER_PASSWORD->value ,fake()->password());
//            $this->setGlobal(TestVariableLabels::USER_EMAIL->value ,fake()->email());
//            $this->setGlobal(TestVariableLabels::ACCOUNT_NAME->value ,fake()->company());
//            $this->setGlobal(TestVariableLabels::ACCOUNT_DOCUMENT->value ,fake()->cnpj());
//            $this->setGlobal(TestVariableLabels::ACCOUNT_ADDRESS->value ,fake()->address());
            $this->getGlobalData();
        }
    }

    public function testAccountUserCreation(): void {
        $response = $this->createAccount();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "success",
            "data" => [
                "name",
                "email",
                "account_ids",
                "updated_at",
                "created_at",
                "id"
            ]
        ]);

    }

    public function testAccountTokenCreation(): void {
        $response = $this->createToken();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "success",
            "token",
        ]);
    }

    public function testAccountCreation(): void {

    }

    public function testAccountList(): void {

    }

    public function testErrorAccountTokenCreationWithoutAuthorization(): void {

    }

    public function testErrorAccountTokenCreationWithWrongCredentials(): void {

    }

    public function testErrorAccountUserCreationWithoutAuthorization(): void {

    }

    public function testErrorAccountUserCreationAccountNotExists(): void {

    }

    public function testAccountUsersList(): void {

    }

    protected function createAccount(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/users",
            data: $this->payloadUserCreation(),
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

    protected function payloadUserCreation(): array {
        return [
            "name" => fake()->name(),
            "email" => fake()->email(),
            "password" => fake()->password(),
            "account_id" => $this->getGlobal(TestVariableLabels::MAIN_ACCOUNT_ID->value)
        ];
    }

    protected function payloadTokenCreation(): array {
        return [
            "token_name" => "Token Test",
            "user_id" => $this->main_user_id,
            "password" => $this->user_password,
        ];
    }

}
