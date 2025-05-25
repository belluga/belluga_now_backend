<?php

namespace Tests\Api\default;

use Illuminate\Support\Str;
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

    protected string $user_email_1 {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::USER_EMAIL_1->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::USER_EMAIL_1->value, fake()->email());
            }
            return $this->getGlobal(TestVariableLabels::USER_EMAIL_1->value);
        }
    }

    protected string $user_email_2 {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::USER_EMAIL_2->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::USER_EMAIL_2->value, fake()->email());
            }
            return $this->getGlobal(TestVariableLabels::USER_EMAIL_2->value);
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

    protected string $tenant_name {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::TENANT_1_NAME->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::TENANT_1_NAME->value, "Belluga Solutions Test");
            }
            return $this->getGlobal(TestVariableLabels::TENANT_1_NAME->value);
        }
    }

    protected string $tenant_1_slug {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_1_SLUG->value, $value);
        }
    }

    protected string $main_user_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::LANDLORD_USER_ID->value, $value);
            $this->main_user_id = $value;
        }
    }

    protected string $landlord_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::LANDLORD_TOKEN->value, $value);
            $this->landlord_token = $value;
        }
    }

    public function testInitiate(): void {
        $response = $this->initiate();

        $response->assertStatus(201);

        $response->assertJsonStructure([
            "data" => [
                "token",
                "user",
                "tenant"
            ],
        ]);

        $this->main_user_id = $response->json()['data']['user']["id"];
        $this->landlord_token = $response->json()['data']['token'];
        $this->tenant_1_slug = $response->json()['data']['tenant']["slug"];
    }

    public function testInitiateAgain(): void {
        $response = $this->initiate();

        $response->assertStatus(403);

        $response->assertJsonStructure([
            "message",
            "errors"
        ]);
    }

    protected function initiate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/initialize",
            data: $this->payloadInitiate(),
        );
    }

    protected function payloadInitiate(): array {
        return [
            "user" => [
                "name" => $this->user_name,
                "emails" => [
                    $this->user_email_1,
                    $this->user_email_2,
                ],
                "password" => $this->user_password
            ],
            "tenant" => [
                "name" => $this->tenant_name,
                "subdomain" => Str::slug($this->tenant_name),
                "domains" => [
                    "localhost"
                ]
            ]
        ];


    }
}
