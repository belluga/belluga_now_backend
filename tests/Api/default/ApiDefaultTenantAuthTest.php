<?php

namespace Tests\Api\default;

use App\Models\Landlord\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultTenantAuthTest extends TestCaseAuthenticated {
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

    protected ?string $secondary_landlord_user_id {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_ID->value);
        }
    }

    protected ?string $secondary_landlord_token {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_TOKEN->value);
        }

        set(?string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_LANDLORD_TOKEN->value, $value);
            $this->secondary_landlord_token = $value;
        }
    }

    public function testUserLoginWrongPassword(): void {

//        $response = $this->userLoginWrongPassword();
//
//        $response->assertStatus(403);
//
//        $response->assertJsonStructure([
//            "errors" => [
//                "credentials"
//            ],
//        ]);

    }

    public function testUserLoginWrongEmail(): void {

//        $response = $this->userLoginWrongEmail();
//
//        $response->assertStatus(403);
//
//        $response->assertJsonStructure([
//            "errors" => [
//                "credentials"
//            ],
//        ]);

    }

    public function testUserLoginLogoutSuccess(): void {

//        $response = $this->userLoginSuccess("device1");
//
//        $response->assertStatus(200);
//
//        $response->assertJsonStructure([
//            "data" => [
//                "user",
//                "token",
//            ],
//        ]);
//
//        $this->secondary_landlord_token = $response->json()['data']['token'];
//
//        $response = $this->userLogout("device1");
//
//        $response->assertStatus(200);
//
//        $this->secondary_landlord_token = null;
    }

    public function testUserLoginLogoutManyDevicesSuccess(): void {

//        $response = $this->userLoginSuccess("device1");
//
//        $this->secondary_landlord_token = $response->json()['data']['token'];
//
//        $response = $this->userLoginSuccess("device2");
//
//        $count = PersonalAccessToken::where('tokenable_id', $this->secondary_landlord_user_id)->count();
//
//        assert($count === 2);
//
//        $response = $this->userLogout(all_devices: true);
//
//        $response->assertStatus(200);
//
//        $response = $this->userLoginSuccess("default");
//
//        $this->secondary_landlord_token = $response->json()['data']['token'];
    }

    protected function userLogout(?string $device = null, ?bool $all_devices = null): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/auth/logout",
            data: $this->payloadUserLogout($device, $all_devices),
            headers: [
                'Authorization' => "Bearer $this->secondary_landlord_token",
                'Content-Type' => 'application/json'
            ]
        );
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

    protected function userLoginSuccess(string $device): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/auth/login",
            data: $this->payloadUserLoginSuccess($device)
        );
    }

    protected function payloadUserLogout(?string $device, ?bool $all_devices): array {

        $return = [];
        if ($device !== null) {
            $return["device"] = $device;
        }

        if($all_devices !== null) {
            $return["all_devices"] = $all_devices;
        }

        return $return;
    }

    protected function payloadUserLoginSuccess(String $device): array {
        return [
            "email" => $this->secondary_user_email,
            "password" => $this->secondary_user_password,
            "device_name" => $device
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
