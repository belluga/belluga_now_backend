<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;

class ApiDefaultInitializeTest extends TestCase {
    protected string $user_password {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::USER_PASSWORD->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::USER_PASSWORD->value, fake()->password());
            }
            return $this->getGlobal(TestVariableLabels::USER_PASSWORD->value);
        }
    }
    protected string $user_email {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::USER_EMAIL->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::USER_EMAIL->value, fake()->email());
            }
            return $this->getGlobal(TestVariableLabels::USER_EMAIL->value);
        }
    }

    protected string $user_name {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::USER_NAME->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::USER_NAME->value, fake()->name());
            }
            return $this->getGlobal(TestVariableLabels::USER_NAME->value);
        }
    }

    protected string $account_name {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::ACCOUNT_NAME->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::ACCOUNT_NAME->value, fake()->company());
            }
            return $this->getGlobal(TestVariableLabels::ACCOUNT_NAME->value);
        }
    }

    protected string $account_document {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::ACCOUNT_DOCUMENT->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::ACCOUNT_DOCUMENT->value, fake()->cnpj());
            }
            return $this->getGlobal(TestVariableLabels::ACCOUNT_DOCUMENT->value);
        }
    }

    protected string $account_address {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::ACCOUNT_ADDRESS->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::ACCOUNT_ADDRESS->value, fake()->address());
            }
            return $this->getGlobal(TestVariableLabels::ACCOUNT_ADDRESS->value);
        }
    }

    protected string $main_user_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::MAIN_USER_ID->value, $value);
            $this->main_user_id = $value;
        }
    }

    protected string $main_account_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::MAIN_ACCOUNT_ID->value, $value);
            $this->main_account_id = $value;
        }
    }

    protected string $main_account_slug {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::MAIN_ACCOUNT_SLUG->value, $value);
            $this->main_account_slug = $value;
        }
    }

    protected string $main_account_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::MAIN_ACCOUNT_TOKEN->value, $value);
            $this->main_account_token = $value;
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

        $this->main_user_id = $response->json()['data']['user']["id"];
        $this->main_account_id = $response->json()['data']['account']["id"];
        $this->main_account_slug = $response->json()['data']['account']["slug"];
        $this->main_account_token = $response->json()['data']["token"];
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
