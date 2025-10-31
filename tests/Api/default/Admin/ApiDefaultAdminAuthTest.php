<?php

namespace Tests\Api\default\Admin;

use App\Models\Landlord\PersonalAccessToken;
use App\Models\Landlord\Tenant;
use Illuminate\Testing\TestResponse;
use Tests\TestCaseAuthenticated;
use Tests\Api\Traits\AccountAuthFunctions;

class ApiDefaultAdminAuthTest extends TestCaseAuthenticated {
    use AccountAuthFunctions;

    protected string $base_api_tenant {
        get {
            return "http://{$this->landlord->tenant_primary->subdomain}.{$this->host}/api/";
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

        $this->ensureSecondaryEmailRegistered();

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

    public function testAdminTokenValidateRejectsTenantToken(): void
    {
        $email = fake()->unique()->safeEmail();
        $password = 'SecurePass!123';

        $tenantBase = "http://{$this->landlord->tenant_primary->subdomain}.{$this->host}/api/";
        $tenantDomain = 'tenant.belluga.test';
        Tenant::query()->first()?->makeCurrent();

        $this->json(
            method: 'post',
            uri: "{$tenantBase}auth/register/password",
            data: [
                'name' => 'Tenant Token Check',
                'email' => $email,
                'password' => $password,
            ],
            headers: [
                'X-App-Domain' => $tenantDomain,
            ]
        )->assertStatus(201);

        $login = $this->json(
            method: 'post',
            uri: "{$tenantBase}auth/login",
            data: [
                'email' => $email,
                'password' => $password,
                'device_name' => 'tenant-token-check',
            ],
            headers: [
                'X-App-Domain' => $tenantDomain,
            ]
        );

        $login->assertStatus(200);
        $tenantToken = $login->json('data.token');

        $response = $this->json(
            method: 'get',
            uri: "admin/api/auth/token_validate",
            headers: [
                'Authorization' => "Bearer $tenantToken",
                'Content-Type' => 'application/json'
            ]
        );

        $response->assertStatus(401);
    }

    public function testAdminLoginRejectsPasswordExceedingMaxLength(): void
    {
        $response = $this->json(
            method: 'post',
            uri: "admin/api/auth/login",
            data: [
                "email" => $this->landlord->user_cross_tenant_admin->email_1,
                "password" => str_repeat('A', 33),
                "device_name" => 'max-length-check',
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.password.0', 'The password field must not be greater than 32 characters.');
    }

    public function testAdminLoginRejectsDeviceNameExceedingMaxLength(): void
    {
        $response = $this->json(
            method: 'post',
            uri: "admin/api/auth/login",
            data: [
                "email" => $this->landlord->user_cross_tenant_admin->email_1,
                "password" => $this->landlord->user_cross_tenant_admin->password,
                "device_name" => str_repeat('d', 300),
            ]
        );

        $response->assertStatus(422);
        $response->assertJsonPath('errors.device_name.0', 'The device name field must not be greater than 255 characters.');
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

    public function testLandlordAnonymousIdentityEndpointNotAvailable(): void {
        $response = $this->json(
            method: 'post',
            uri: 'admin/api/v1/anonymous/identities',
            data: [
                'device_name' => 'landlord-device',
                'fingerprint' => [
                    'hash' => hash('sha256', 'landlord-device'),
                    'user_agent' => 'AdminTest/1.0',
                ],
            ]
        );

        $this->assertEquals(404, $response->status());
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

    protected function ensureSecondaryEmailRegistered(): void {
        if (empty($this->landlord->user_cross_tenant_admin->email_2)) {
            $this->landlord->user_cross_tenant_admin->email_2 = fake()->unique()->safeEmail();
        }

        $deviceName = 'email2-setup';

        $login = $this->userLoginSuccessEmail1($deviceName);
        $login->assertStatus(200);

        $this->landlord->user_cross_tenant_admin->token = $login->json('data.token');
        $currentEmails = array_map('strtolower', $login->json('data.user.emails') ?? []);
        $desiredEmail = strtolower($this->landlord->user_cross_tenant_admin->email_2);

        if (!in_array($desiredEmail, $currentEmails, true)) {
            $response = $this->json(
                method: 'patch',
                uri: "admin/api/profile/emails",
                data: [
                    'email' => $this->landlord->user_cross_tenant_admin->email_2,
                ],
                headers: [
                    'Authorization' => "Bearer {$this->landlord->user_cross_tenant_admin->token}",
                    'Content-Type' => 'application/json'
                ]
            );

            $response->assertStatus(200);
        }

        $this->userLogout($deviceName)->assertStatus(200);
        $this->landlord->user_cross_tenant_admin->token = "";
    }

    protected function payloadUserLoginWrongPassword(): array {
        return [
            "email" => $this->landlord->user_cross_tenant_admin->email_1,
            "password" => fake()->password(8),
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

