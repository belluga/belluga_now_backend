<?php

namespace Tests\Api\default;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultLandlordUserTest extends TestCaseAuthenticated {
    protected string $secondary_user_password {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_PASSWORD->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_PASSWORD->value, fake()->password());
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
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_ID->value, $value);
            $this->secondary_landlord_user_id = $value;
        }
    }

    public function testUserCreate(): void {
        $response = $this->userCreate();

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
        $response = $this->userCreate();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "emails"
            ],
        ]);
    }

    protected function userCreate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/users",
            data: $this->payloadUserCreate(),
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
}
