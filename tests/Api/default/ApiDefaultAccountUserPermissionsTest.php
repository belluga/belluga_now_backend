<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountUserPermissionsTest extends TestCaseAuthenticated
{

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

    protected string $account_user_admin_email_1 {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_EMAIL_1->value, $value);
            $this->account_user_admin_email_1 = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_EMAIL_1->value);
        }
    }

    protected string $account_user_admin_email_2 {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_EMAIL_2->value, $value);
            $this->account_user_admin_email_2 = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_EMAIL_2->value);
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

    public function testLoginSuccess(): void {
        $responseUserAdmin = $this->userLogin([
                "email" => $this->account_user_admin_email_1,
                "password" => $this->account_user_admin_password,
                "device_name" => "test_1",
            ]
        );
        $responseUserAdmin->assertStatus(200);

        $responseUserAdmin->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ],
        ]);
        $this->account_user_admin_token_device_1 = $responseUserAdmin->json()['data']['token'];

        $responseUserUserManage = $this->userLogin([
                "email" => $this->account_user_usermanage_email,
                "password" => $this->account_user_usermanage_password,
                "device_name" => "test",
            ]
        );
        $responseUserUserManage->assertStatus(200);

        $responseUserUserManage->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ]
        ]);

        $this->account_user_usermanage_token = $responseUserUserManage->json()['data']['token'];

        $responseUserRoleManage = $this->userLogin([
                "email" => $this->account_user_rolemanage_email,
                "password" => $this->account_user_rolemanage_password,
                "device_name" => "test",
            ]
        );
        $responseUserRoleManage->assertStatus(200);

        $responseUserRoleManage->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ]
        ]);

        $this->account_user_rolemanage_token = $responseUserRoleManage->json()['data']['token'];

    }

    public function testRolesCreate(): void
    {
//        $roleName = "Test Admin Role";
//
//        $response = $this->rolesCreate([
//            "name" => $roleName,
//            "description" => "Role for testing purposes",
//            "permissions" => ["user.view", "user.create", "role.view"],
//        ]);
//
//        $response->assertStatus(201);
//        $response->assertJsonStructure([
//            "data" => [
//                "name",
//                "permissions",
//                "created_at",
//            ]
//        ]);
//
//        $this->role_id = $response->json()['data']['id'];

    }

    public function testRolesList(): void
    {
//        $rolesList = $this->rolesList();
//        $rolesList->assertOk();
//
//        $responseData = $rolesList->json();
//        $this->assertEquals(2, $responseData['total']);
//        $this->assertArrayHasKey('total', $responseData);
//        $this->assertArrayHasKey('data', $responseData);
//        $this->assertArrayHasKey('last_page', $responseData);
//        $this->assertArrayHasKey('current_page', $responseData);
//        $this->assertArrayHasKey('per_page', $responseData);
    }

    public function testRolesShow(): void
    {
//        $rolesShow = $this->rolesShow($this->role_id);
//        $rolesShow->assertOk();
//        $rolesShow->assertJsonStructure([
//            "data" => [
//                "name",
//                "permissions",
//                "created_at",
//            ],
//        ]);
    }

    public function testRolesUpdate(): void
    {
//        $roleUpdate = $this->rolesUpdate(
//            $this->role_id,
//            [
//                "name" => "Updated Role Name",
//                "permissions" => ["user.view", "user.create", "role.view", "role.create"],
//            ]
//        );
//
//        $roleUpdate->assertStatus(200);
//
//        $rolesShow = $this->rolesShow($this->role_id);
//        $rolesShow->assertOk();
//
//        $this->assertEquals("Updated Role Name", $rolesShow->json()['data']['name']);
//        $this->assertEquals(
//            ["user.view", "user.create", "role.view", "role.create"],
//            $rolesShow->json()['data']['permissions']
//        );
    }

    public function testRolesDelete(): void
    {
//        $deleteResponse = $this->rolesDelete($this->role_id);
//        $deleteResponse->assertStatus(200);
//
//        $showResponse = $this->rolesShow($this->role_id);
//        $showResponse->assertStatus(404);
    }

    public function testRolesRestore(): void
    {
//        $restoreResponse = $this->rolesRestore($this->role_id);
//        $restoreResponse->assertStatus(200);
//
//        $showResponse = $this->rolesShow($this->role_id);
//        $showResponse->assertOk();
    }

    public function testRolesDeleteFlow(): void
    {
//        $deleteResponse = $this->rolesDelete($this->role_id);
//        $deleteResponse->assertStatus(200);
//
//        $showResponse = $this->rolesShow($this->role_id);
//        $showResponse->assertStatus(404);
//
//        $rolesListArchived = $this->rolesListArchived();
//        $rolesListArchived->assertOk();
//
//        $responseData = $rolesListArchived->json();
//        $this->assertEquals(1, $responseData['total']);
//        $this->assertArrayHasKey('total', $responseData);
//        $this->assertArrayHasKey('data', $responseData);
//        $this->assertArrayHasKey('last_page', $responseData);
//        $this->assertArrayHasKey('current_page', $responseData);
//        $this->assertArrayHasKey('per_page', $responseData);
//
//        $rolesListArchived = $this->forceDelete($this->role_id);
//        $rolesListArchived->assertOk();
//
//        $rolesListArchived = $this->rolesListArchived();
//        $responseData = $rolesListArchived->json();
//        $this->assertEquals(0, $responseData['total']);
//
//        $rolesList = $this->rolesList();
//        $responseData = $rolesList->json();
//        $this->assertEquals(1, $responseData['total']);
    }

    public function testLogoutUsers(): void {
        $responseUserAdmin = $this->userLogout([
                "device_name" => "test_1",
            ], $this->account_user_admin_token_device_1
        );
        $responseUserAdmin->assertStatus(200);
        $this->account_user_admin_token_device_1 = "";

        $responseUserUserManage = $this->userLogout([
                "device_name" => "test",
            ],
            $this->account_user_usermanage_token
        );
        $responseUserUserManage->assertStatus(200);
        $this->account_user_usermanage_token = "";

        $responseUserRoleManage = $this->userLogout([
                "device_name" => "test",
            ],
            $this->account_user_rolemanage_token
        );
        $responseUserRoleManage->assertStatus(200);
        $this->account_user_rolemanage_token = "";
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
