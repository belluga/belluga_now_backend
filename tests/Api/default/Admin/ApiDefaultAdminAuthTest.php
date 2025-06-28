<?php

namespace Tests\Api\default\Admin;

use App\Models\Landlord\PersonalAccessToken;
use Illuminate\Testing\TestResponse;
use Tests\TestCaseAuthenticated;

class ApiDefaultAdminAuthTest extends TestCaseAuthenticated {

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

    public function testUserLoginLogoutSuccessEmail1(): void {

        $response = $this->userLoginSuccessEmail1("device1");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ],
        ]);

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];

        $response = $this->userLogout("device1");

        $response->assertStatus(200);

        $this->landlord->user_cross_tenant_admin->token = "";
    }

    public function testUserLoginLogoutSuccessEmail2(): void {

        $response = $this->userLoginSuccessEmail2("device1");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ],
        ]);

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];

        $response = $this->userLogout("device1");

        $response->assertStatus(200);

        $this->landlord->user_cross_tenant_admin->token = "";
    }

    public function testUserLoginLogoutManyDevicesSuccess(): void {

        $response = $this->userLoginSuccessEmail1("device1");

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];

        $this->userLoginSuccessEmail1("device2");

        $count = PersonalAccessToken::where('tokenable_id', $this->landlord->user_cross_tenant_admin->user_id)->count();

        assert($count === 2);

        $response = $this->userLogout(all_devices: true);

        $response->assertStatus(200);

        $response = $this->userLoginSuccessEmail1("default");

        $this->landlord->user_cross_tenant_admin->token = $response->json()['data']['token'];
    }

    public function testLoginWithToken(): void {
        $response = $this->userLoginWithToken($this->landlord->user_superadmin->token);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            "data" => [
                "user"
            ]
        ]);

        $response = $this->userLoginWithToken($this->landlord->user_cross_tenant_admin->token);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            "data" => [
                "user"
            ]
        ]);
    }

    public function testLoginWithTokenError(): void {
        $response = $this->userLoginWithToken("123");
        $response->assertStatus(401);
    }

    protected function userLoginWithToken(string $token): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/auth/token_validate",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function userLogout(?string $device = null, ?bool $all_devices = null): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/auth/logout",
            data: $this->payloadUserLogout($device, $all_devices),
            headers: [
                'Authorization' => "Bearer {$this->landlord->user_cross_tenant_admin->token}",
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

    protected function userLoginSuccessEmail1(string $device): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/auth/login",
            data: $this->payloadUserLoginSuccessEmail1($device)
        );
    }

    protected function userLoginSuccessEmail2(string $device): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/auth/login",
            data: $this->payloadUserLoginSuccessEmail2($device)
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

    protected function payloadUserLoginSuccessEmail1(String $device): array {
        return [
            "email" => $this->landlord->user_cross_tenant_admin->email_1,
            "password" => $this->landlord->user_cross_tenant_admin->password,
            "device_name" => $device
        ];
    }

    protected function payloadUserLoginSuccessEmail2(String $device): array {
        return [
            "email" => $this->landlord->user_cross_tenant_admin->email_2,
            "password" => $this->landlord->user_cross_tenant_admin->password,
            "device_name" => $device
        ];
    }

    protected function payloadUserLoginWrongPassword(): array {
        return [
            "email" => $this->landlord->user_cross_tenant_admin->email_1,
            "password" => fake()->password(),
            "device_name" => "test"
        ];
    }

    protected function payloadUserLoginWrongEmail(): array {
        return [
            "email" => fake()->email(),
            "password" => $this->landlord->user_cross_tenant_admin->password,
            "device_name" => "test"
        ];
    }
}
