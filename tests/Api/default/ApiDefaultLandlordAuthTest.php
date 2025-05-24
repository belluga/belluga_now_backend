<?php

namespace Tests\Api\default;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultLandlordAuthTest extends TestCaseAuthenticated {
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

    protected string $secondary_landlord_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_LANDLORD_TOKEN->value, $value);
            $this->secondary_landlord_token = $value;
        }
    }

    public function testUserLoginWrongPassword(): void {

        $response = $this->userLoginWrongPassword();

        $response->assertStatus(403);

        $response->assertJsonStructure([
            "errors" => [
                "credentials"
            ],
        ]);

    }

    public function testUserLoginWrongEmail(): void {

        $response = $this->userLoginWrongEmail();

        $response->assertStatus(403);

        $response->assertJsonStructure([
            "errors" => [
                "credentials"
            ],
        ]);

    }

    public function testUserLoginSuccess(): void {

        $response = $this->userLoginSuccess();

        $response->assertStatus(200);

        $response->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ],
        ]);

        $this->secondary_landlord_token = $response->json()['data']['token'];
    }

    protected function userLoginWrongPassword(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/auth/login",
            data: $this->payloadUserLoginWrongPassword()
        );
    }

    protected function userLoginWrongEmail(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/auth/login",
            data: $this->payloadUserLoginWrongPassword()
        );
    }

    protected function userLoginSuccess(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/auth/login",
            data: $this->payloadUserLoginSuccess()
        );
    }

    protected function payloadUserLoginSuccess(): array {
        return [
            "email" => $this->secondary_user_email,
            "password" => $this->secondary_user_password,
            "device_name" => "test"
        ];
    }

    protected function payloadUserLoginWrongPassword(): array {
        return [
            "email" => $this->secondary_user_email,
            "password" => fake()->password(),
            "device_name" => "test"
        ];
    }

    protected function payloadUserLoginWrongEmail(): array {
        return [
            "email" => fake()->email(),
            "password" => $this->secondary_user_password,
            "device_name" => "test"
        ];
    }
}
