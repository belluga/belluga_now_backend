<?php

namespace Tests\Api\default;

use App\Models\Landlord\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountAuthTest extends TestCaseAuthenticated {

    protected string $base_api_url {
        get {
            return "http://{$this->tenant_subdomain}.localhost/api/";
        }
    }

    protected string $tenant_subdomain {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SUBDOMAIN->value);
        }
    }

    protected string $account_user_admin_email_1 {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_EMAIL_1->value);
        }
    }

    protected string $account_user_admin_email_2 {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_EMAIL_2->value);
        }
    }

    protected string $account_user_admin_password {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_PASSWORD->value);
        }
    }

    protected string $account_user_admin_token_device_1 {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN->value, $value);
            $this->account_user_admin_token_device_1 = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN->value);
        }
    }

    protected string $account_user_admin_token_device_2 {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_DEVICE_2_TOKEN->value, $value);
            $this->account_user_admin_token_device_2 = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_DEVICE_2_TOKEN->value);
        }
    }

    public function testUserLoginWrongPassword(): void {

        $response = $this->userLogin(
            data: [
                "email" => $this->account_user_admin_email_1,
                "password" => fake()->password(8),
                "device_name" => "test",
            ]
        );

        $response->assertStatus(403);

        $response->assertJsonStructure([
            "errors" => [
                "credentials"
            ],
        ]);

        $response = $this->userLogin(
            data: [
                "email" => $this->account_user_admin_email_2,
                "password" => fake()->password(8),
                "device_name" => "test",
            ]
        );

        $response->assertStatus(403);

        $response->assertJsonStructure([
            "errors" => [
                "credentials"
            ],
        ]);

    }

    public function testUserLoginWrongEmail(): void {

        $response = $this->userLogin([
                "email" => fake()->email,
                "password" => $this->account_user_admin_password,
                "device_name" => "test",
            ]
        );

        $response->assertStatus(403);

        $response->assertJsonStructure([
            "errors" => [
                "credentials"
            ],
        ]);

    }

    public function testUserLoginLogoutManyDevicesSuccess(): void {

        $responseUserAdmin = $this->userLogin([
                "email" => $this->account_user_admin_email_1,
                "password" => $this->account_user_admin_password,
                "device_name" => "test_1",
            ]
        );
        $responseUserAdmin->assertStatus(200);
        $this->account_user_admin_token_device_1 = $responseUserAdmin->json()['data']['token'];

        $responseUserAdmin->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ],
        ]);

        $responseUserAdmin = $this->userLogin([
                "email" => $this->account_user_admin_email_2,
                "password" => $this->account_user_admin_password,
                "device_name" => "test_2",
            ]
        );
        $responseUserAdmin->assertStatus(200);
        $this->account_user_admin_token_device_2 = $responseUserAdmin->json()['data']['token'];

        $responseUserAdmin->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ],
        ]);

        $responseLogout = $this->userLogout([
                "all_devices" => true
            ],
            $this->account_user_admin_token_device_1
        );

        $responseLogout->assertStatus(200);
        $this->account_user_admin_token_device_1 = "";
        $this->account_user_admin_token_device_2 = "";

    }

    protected function userLogin(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: $this->base_api_url."auth/login",
            data: $data
        );
    }

    protected function userLogout(array $data, string $token): TestResponse {
        return $this->json(
            method: 'post',
            uri: $this->base_api_url."auth/logout",
            data: $data,
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }
}
