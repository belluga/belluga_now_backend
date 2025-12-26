<?php

namespace Tests\Api\v1\Accounts\Auth\Contracts;

use Tests\Api\Traits\AccountAuthFunctions;
use Tests\Helpers\UserLabels;
use Tests\TestCaseAccount;

abstract class ApiV1AccountAuthTestContract extends TestCaseAccount
{

    use AccountAuthFunctions;

    public function testUserLoginWrongPassword(): void {

        $fake_user_label = new UserLabels("fake_user_label_wrong_password");
        $fake_user_label->email_1 = $this->account->user_visitor->email_1;
        $fake_user_label->password = fake()->password(8);

        $response = $this->accountLogin($fake_user_label);

        $response->assertStatus(403);

        $response->assertJsonStructure([
            "errors" => [
                "credentials"
            ],
        ]);
    }

    public function testUserLoginWrongEmail(): void {

        $fake_user_label = new UserLabels("fake_user_label_wrong_email");
        $fake_user_label->email_1 = fake()->email;
        $fake_user_label->password = $this->account->user_visitor->password;

        $response = $this->accountLogin($fake_user_label);

        $response->assertStatus(403);

        $response->assertJsonStructure([
            "errors" => [
                "credentials"
            ],
        ]);

    }

    public function testUserLoginLogoutManyDevicesSuccess(): void {

        $device_1 = "device_1";
        $device_2 = "device_2";

        $responseUserAdmin = $this->accountLogin(
            $this->account->user_visitor,
            $device_1);

        $responseUserAdmin->assertStatus(200);
        $this->account->user_visitor->token = $responseUserAdmin->json()['data']['token'];

        $responseUserAdmin->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ],
        ]);

        $responseUserAdmin = $this->accountLogin(
            $this->account->user_visitor,
            $device_2);

        $responseUserAdmin->assertStatus(200);
        $this->account->user_visitor->token = $responseUserAdmin->json()['data']['token'];

        $responseUserAdmin->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ],
        ]);

        $responseLogout = $this->accountLogout(
            user: $this->account->user_visitor,
            all_devices: true,
        );

        $responseLogout->assertStatus(200);
        $this->account->user_visitor->token = "";

    }

    public function testLogin(): void {

        $responseUserAdmin = $this->accountLogin($this->account->user_admin);

        $responseUserAdmin->assertStatus(200);
        $this->account->user_admin->token = $responseUserAdmin->json()['data']['token'];

        $responseUserAdmin = $this->accountLogin($this->account->user_visitor);

        $responseUserAdmin->assertStatus(200);
        $this->account->user_visitor->token = $responseUserAdmin->json()['data']['token'];
    }

    public function testLoginWithToken(): void {
        $response = $this->accountTokenValidate($this->account->user_admin->token);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            "data" => [
                "user"
            ]
        ]);

        $response = $this->accountTokenValidate($this->account->user_visitor->token);
        $response->assertStatus(200);

        $response->assertJsonStructure([
            "data" => [
                "user"
            ]
        ]);
    }

    public function testLoginWithTokenError(): void {
        $response = $this->accountTokenValidate("123");
        $response->assertStatus(401);
    }

    public function testUserLoginRejectsPasswordExceedingMaxLength(): void
    {
        $user = new UserLabels('login_max_password');
        $user->email_1 = $this->account->user_admin->email_1;
        $user->password = str_repeat('A', 33);

        $response = $this->accountLogin($user);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.password.0', 'The password field must not be greater than 32 characters.');
    }

    public function testUserLoginRejectsDeviceNameExceedingMaxLength(): void
    {
        $deviceName = str_repeat('d', 300);

        $response = $this->accountLogin($this->account->user_admin, $deviceName);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.device_name.0', 'The device name field must not be greater than 255 characters.');
    }
}
