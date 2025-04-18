<?php

namespace Tests\Api\default;

use App\Models\Account;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\TestVariableLabels;

class ApiDefaultInitializeTest extends TestCase {
    protected string $user_password;
    protected string $user_email;

    protected string $user_name;

    protected string $account_name;

    protected string $account_document;

    protected string $account_address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->getGlobalData();
    }

    protected function getGlobalData(): void{
        try {
            $this->user_name = $this->getGlobal(TestVariableLabels::USER_NAME->value);
            $this->user_email = $this->getGlobal(TestVariableLabels::USER_EMAIL->value);
            $this->user_password = $this->getGlobal(TestVariableLabels::USER_PASSWORD->value);
            $this->account_name = $this->getGlobal(TestVariableLabels::ACCOUNT_NAME->value);
            $this->account_document = $this->getGlobal(TestVariableLabels::ACCOUNT_DOCUMENT->value);
            $this->account_address = $this->getGlobal(TestVariableLabels::ACCOUNT_ADDRESS->value);
        }catch (\Exception $e){
            $this->setGlobal(TestVariableLabels::USER_NAME->value ,fake()->name());
            $this->setGlobal(TestVariableLabels::USER_PASSWORD->value ,fake()->password());
            $this->setGlobal(TestVariableLabels::USER_EMAIL->value ,fake()->email());
            $this->setGlobal(TestVariableLabels::ACCOUNT_NAME->value ,fake()->company());
            $this->setGlobal(TestVariableLabels::ACCOUNT_DOCUMENT->value ,fake()->cnpj());
            $this->setGlobal(TestVariableLabels::ACCOUNT_ADDRESS->value ,fake()->address());
            $this->getGlobalData();
        }
    }

    public function testInitiate(): void {
        $response = $this->initiate();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "success",
            "data" => [
                "token",
                "user",
                "account"
            ]
        ]);

        $this->setGlobal(TestVariableLabels::MAIN_USER_ID->value ,$response->json()['data']['user']["id"]);
        $this->setGlobal(TestVariableLabels::MAIN_ACCOUNT_ID->value,$response->json()['data']['account']["id"]);
        $this->setGlobal(TestVariableLabels::MAIN_ACCOUNT_TOKEN->value, $response->json()['data']["token"]);
    }

    public function testInitiateAgain(): void {
        $response = $this->initiate();

        $response->assertStatus(403);

        $response->assertJsonStructure([
            "success",
            "message",
            "errors"
        ]);
    }

    protected function initiate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "api/initialize",
            data: $this->payloadInitiate(),
        );
    }

    protected function payloadInitiate(): array {
        return [
            "name" => $this->user_name,
            "email" => $this->user_email,
            "password" => $this->user_password,
            "account" => [
                "name" => $this->account_name,
                "document" => $this->account_document,
                "address" => $this->account_address,
            ]
        ];
    }

}
