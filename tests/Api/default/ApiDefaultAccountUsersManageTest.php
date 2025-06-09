<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountUsersManageTest extends TestCaseAuthenticated
{

    protected string $tenant_subdomain {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SUBDOMAIN->value);
        }
    }

    protected string $main_account_slug {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_MAIN_ACCOUNT_SLUG->value);
        }
    }

    protected string $account_user_admin_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_ID->value, $value);
            $this->account_user_admin_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_ID->value);
        }
    }

    protected string $account_user_usermanage_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_ID->value, $value);
            $this->account_user_usermanage_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_ID->value);
        }
    }

    protected string $account_user_rolemanage_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_ID->value, $value);
            $this->account_user_rolemanage_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_ID->value);
        }
    }

    protected string $account_user_admin_name {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_NAME->value, $value);
            $this->account_user_admin_name = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_NAME->value);
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

    protected string $account_user_usermanage_name {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_NAME->value, $value);
            $this->account_user_usermanage_name = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_NAME->value);
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

    protected string $account_user_rolemanage_name {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_NAME->value, $value);
            $this->account_user_rolemanage_name = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_NAME->value);
        }
    }

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

    public function testUserCreation(): void {
        $this->account_user_admin_name = fake()->name();
        $this->account_user_admin_email = fake()->email();
        $this->account_user_admin_password = fake()->password(8);

        $response = $this->userCreate([
            "name" => $this->account_user_admin_name,
            "emails" => [
                $this->account_user_admin_email,
            ],
            "password" => $this->account_user_admin_password,
            "password_confirmation" => $this->account_user_admin_password,
            "role" => "admin"

        ]);

        $response->assertStatus(201);
        $this->account_user_admin_id = $response->json()['data']["id"];


        $this->account_user_usermanage_name = fake()->name();
        $this->account_user_usermanage_email = fake()->email();
        $this->account_user_usermanage_password = fake()->password(8);

        $response = $this->userCreate([
            "name" => $this->account_user_usermanage_name,
            "emails" => [
                $this->account_user_usermanage_email,
            ],
            "password" => $this->account_user_usermanage_password,
            "password_confirmation" => $this->account_user_usermanage_password,
            "role" => "user-manager"

        ]);

        $response->assertStatus(201);
        $this->account_user_usermanage_id = $response->json()['data']["id"];


        $this->account_user_rolemanage_name = fake()->name();
        $this->account_user_rolemanage_email = fake()->email();
        $this->account_user_rolemanage_password = fake()->password(8);

        $response = $this->userCreate([
            "name" => $this->account_user_rolemanage_name,
            "emails" => [
                $this->account_user_rolemanage_email,
            ],
            "password" => $this->account_user_rolemanage_password,
            "password_confirmation" => $this->account_user_rolemanage_password,
            "role" => "role-manager"

        ]);

        $response->assertStatus(201);
        $this->account_user_rolemanage_id = $response->json()['data']["id"];

    }

    protected function userCreate(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }
}
