<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountUsersManageValidationTest extends TestCaseAuthenticated
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

    public function testUserCreation(): void {

        $response = $this->userCreate([]);
        $response->assertStatus(422);

        $response->assertJsonStructure([
            "message",
            "errors" => [
                "name",
                "emails",
                "password",
                "role"
            ],
        ]);
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
