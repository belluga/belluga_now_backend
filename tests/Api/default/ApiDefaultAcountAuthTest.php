<?php

namespace Tests\Api\default;

use App\Models\Landlord\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultAcountAuthTest extends TestCaseAuthenticated {
    protected string $account_user_rolemanage_email {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_EMAIL->value, $value);
            $this->account_user_rolemanage_email = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_EMAIL->value);
        }
    }

    protected string $account_user_rolemanage_password {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_PASSWORD->value, $value);
            $this->account_user_rolemanage_password = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_PASSWORD->value);
        }
    }

    protected string $account_user_rolemanage_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_TOKEN->value, $value);
            $this->account_user_rolemanage_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_TOKEN->value);
        }
    }

    protected string $account_user_usermanage_email {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_EMAIL->value, $value);
            $this->account_user_usermanage_email = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_EMAIL->value);
        }
    }

    protected string $account_user_usermanage_password {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_PASSWORD->value, $value);
            $this->account_user_usermanage_password = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_PASSWORD->value);
        }
    }

    protected string $account_user_usermanage_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_TOKEN->value, $value);
            $this->account_user_usermanage_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_TOKEN->value);
        }
    }

    protected string $account_user_admin_email {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_EMAIL->value, $value);
            $this->account_user_admin_email = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_EMAIL->value);
        }
    }

    protected string $account_user_admin_password {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_PASSWORD->value, $value);
            $this->account_user_admin_password = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_PASSWORD->value);
        }
    }

    protected string $account_user_admin_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_TOKEN->value, $value);
            $this->account_user_admin_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_TOKEN->value);
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
