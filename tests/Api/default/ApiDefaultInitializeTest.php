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

    protected string $main_user_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::MAIN_USER_ID->value, $value);
            $this->main_user_id = $value;
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
        ];
    }

}
