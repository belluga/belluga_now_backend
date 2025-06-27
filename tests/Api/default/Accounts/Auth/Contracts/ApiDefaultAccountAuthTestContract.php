<?php

namespace Tests\Api\default\Accounts\Auth\Contracts;

use Tests\Api\default\Accounts\Contracts\TestCaseAccount;
use Tests\Api\default\Accounts\Traits\AccountAuthFunctions;
use Tests\Helpers\UserLabels;

abstract class ApiDefaultAccountAuthTestContract extends TestCaseAccount
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
}
