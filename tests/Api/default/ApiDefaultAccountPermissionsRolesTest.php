<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountPermissionsRolesTest extends TestCaseAuthenticated
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

    protected string $main_account_slug {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_MAIN_ACCOUNT_SLUG->value);
        }
    }

    protected string $account_user_rolemanage_email {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_EMAIL->value);
        }
    }

    protected string $account_user_rolemanage_password {
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
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_EMAIL->value);
        }
    }

    protected string $account_user_usermanage_password {
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
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_EMAIL_1->value);
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

    protected string $account_user_visitor_email {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_EMAIL->value);
        }
    }

    protected string $account_user_visitor_password {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_PASSWORD->value);
        }
    }

    protected string $account_user_visitor_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_TOKEN->value, $value);
            $this->account_user_visitor_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_TOKEN->value);
        }
    }

    protected string $role_id_created_by_account_admin_user {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ROLE_ID_CREATED_BY_ACCOUNT_ADMIN_USER->value, $value);
            $this->role_id_created_by_account_admin_user = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ROLE_ID_CREATED_BY_ACCOUNT_ADMIN_USER->value);
        }
    }

    protected string $role_id_created_by_account_rolemanage_user {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ROLE_ID_CREATED_BY_ACCOUNT_ROLEMANAGE_USER->value, $value);
            $this->role_id_created_by_account_rolemanage_user = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ROLE_ID_CREATED_BY_ACCOUNT_ROLEMANAGE_USER->value);
        }
    }

    protected string $main_account_role_admin_id {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_ROLE_ADMIN_ID->value);
        }
    }

    protected string $main_account_role_usermanage_id {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_ROLE_USERMANAGE_ID->value);
        }
    }

    protected string $main_account_role_rolemanage_id {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_ROLE_ROLEMANAGE_ID->value);
        }
    }

    protected string $main_account_role_visitor_id {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_ROLE_VISITOR_ID->value);
        }
    }

    public function testLoginAdminSuccess(): void {
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
    }

    public function testLoginUserManagerSuccess(): void {
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
    }

    public function testLoginRoleManagerSuccess(): void {
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

    public function testLoginVisitorSuccess(): void {
        $responseUserVisitor = $this->userLogin([
                "email" => $this->account_user_visitor_email,
                "password" => $this->account_user_visitor_password,
                "device_name" => "test",
            ]
        );
        $responseUserVisitor->assertStatus(200);

        $responseUserVisitor->assertJsonStructure([
            "data" => [
                "user",
                "token",
            ]
        ]);

        $this->account_user_visitor_token = $responseUserVisitor->json()['data']['token'];
    }

    public function testRolesListWithAdminUser(): void {
        $rolesListAdmin = $this->accountRolesList(
            $this->main_account_slug,
            $this->account_user_admin_token_device_1
        );
        $rolesListAdmin->assertOk();
    }

    public function testRolesListWithUserManageUser(): void {
        $rolesListUserManage = $this->accountRolesList(
            $this->main_account_slug,
            $this->account_user_usermanage_token
        );
        $rolesListUserManage->assertStatus(403);
    }

    public function testRolesListWithRoleManageUser(): void {
        $rolesListRoleManage = $this->accountRolesList(
            $this->main_account_slug,
            $this->account_user_rolemanage_token
        );
        $rolesListRoleManage->assertOk();
    }

    public function testRolesListWithVisitorUser(): void {
        $rolesListVisitor = $this->accountRolesList(
            $this->main_account_slug,
            $this->account_user_visitor_token
        );
        $rolesListVisitor->assertStatus(403);
    }

    public function testRolesCreateWithAdminUser(): void
    {
        $roleCreate = $this->accountRolesCreate(
            $this->main_account_slug,
            $this->account_user_admin_token_device_1,
            [
                "name" => "Role By Admin",
                "description" => "Role for testing purposes created by Account Admin User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $roleCreate->assertStatus(201);
        $this->role_id_created_by_account_admin_user = $roleCreate->json()['data']['id'];

    }

    public function testRolesCreateWithRoleManageUser(): void
    {
        $roleCreate = $this->accountRolesCreate(
            $this->main_account_slug,
            $this->account_user_rolemanage_token,
            [
                "name" => "Role By Role Manager",
                "description" => "Role for testing purposes created by Role Manager User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $roleCreate->assertStatus(201);
        $this->role_id_created_by_account_rolemanage_user = $roleCreate->json()['data']['id'];

    }

    public function testRolesCreateWithUserManageUser(): void
    {
        $roleCreate = $this->accountRolesCreate(
            $this->main_account_slug,
            $this->account_user_usermanage_token,
            [
                "name" => "Role By User Manager",
                "description" => "Role for testing purposes created by User Manager User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $roleCreate->assertStatus(403);

    }

    public function testRolesCreateWithVisitorUser(): void
    {
        $roleCreate = $this->accountRolesCreate(
            $this->main_account_slug,
            $this->account_user_visitor_token,
            [
                "name" => "Role By Visitor",
                "description" => "Role for testing purposes created by Visitor User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $roleCreate->assertStatus(403);

    }

    public function testRolesShowWithAdminUser(): void
    {
        $rolesShow = $this->accountRolesShow(
            $this->main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->account_user_admin_token_device_1
        );
        $rolesShow->assertOk();
        $rolesShow->assertJsonStructure([
            "data" => [
                "name",
                "permissions",
                "created_at",
            ],
        ]);
    }

    public function testRolesShowWithRoleManagerUser(): void
    {
        $rolesShow = $this->accountRolesShow(
            $this->main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->account_user_rolemanage_token
        );
        $rolesShow->assertOk();
        $rolesShow->assertJsonStructure([
            "data" => [
                "name",
                "permissions",
                "created_at",
            ],
        ]);
    }

    public function testRolesShowWithUserManagerUser(): void
    {
        $rolesShow = $this->accountRolesShow(
            $this->main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->account_user_usermanage_token
        );
        $rolesShow->assertStatus(403);
    }

    public function testRolesShowWithVisitorUser(): void
    {
        $rolesShow = $this->accountRolesShow(
            $this->main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->account_user_visitor_token
        );
        $rolesShow->assertStatus(403);
    }

    public function testRolesUpdateWithAdminUser(): void
    {
        $token = $this->account_user_admin_token_device_1;
        $role_id = $this->role_id_created_by_account_admin_user;

        $roleUpdate = $this->accountRolesUpdate(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By Admin Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $roleUpdate->assertStatus(200);

        $rolesShow = $this->accountRolesShow(
            $this->main_account_slug,
            $role_id,
            $token
        );
        $rolesShow->assertOk();

        $this->assertEquals("Role By Admin Updated", $rolesShow->json()['data']['name']);
        $this->assertEquals(
            ["user:view", "user:create", "role:view", "role:create"],
            $rolesShow->json()['data']['permissions']
        );
    }

    public function testRolesUpdateWithRoleManagerUser(): void
    {
        $token = $this->account_user_rolemanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;

        $roleUpdate = $this->accountRolesUpdate(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By Role Manager Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $roleUpdate->assertStatus(200);

        $rolesShow = $this->accountRolesShow(
            $this->main_account_slug,
            $role_id,
            $token
        );
        $rolesShow->assertOk();

        $this->assertEquals("Role By Role Manager Updated", $rolesShow->json()['data']['name']);
        $this->assertEquals(
            ["user:view", "user:create", "role:view", "role:create"],
            $rolesShow->json()['data']['permissions']
        );
    }

    public function testRolesUpdateWithUserManagerUser(): void
    {
        $token = $this->account_user_usermanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;

        $roleUpdate = $this->accountRolesUpdate(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By User Manager Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $roleUpdate->assertStatus(403);

    }

    public function testRolesUpdateWithVisitorUser(): void
    {
        $token = $this->account_user_visitor_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;

        $roleUpdate = $this->accountRolesUpdate(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By User Manager Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $roleUpdate->assertStatus(403);

    }

    public function testRolesDeleteWithUserManagerUser(): void
    {
        $token = $this->account_user_usermanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;


        $deleteResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(403);
    }

    public function testRolesDeleteWithVisitorUser(): void
    {
        $token = $this->account_user_visitor_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;


        $deleteResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(403);
    }

    public function testRolesDeleteWithSameRoleAsBackground(): void
    {
        $token = $this->account_user_admin_token_device_1;
        $role_id = $this->role_id_created_by_account_admin_user;

        $deleteResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $role_id
            ]
        );
        $deleteResponse->assertStatus(422);

        $deleteResponse->assertJsonStructure([
            "message",
            "errors" => [
                "role_id"
            ]
        ]);

    }

    public function testRolesDeleteWithAdminUser(): void
    {
        $token = $this->account_user_admin_token_device_1;
        $role_id = $this->role_id_created_by_account_admin_user;

        $deleteResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(200);

        $showResponse = $this->accountRolesShow(
            $this->main_account_slug,
            $role_id,
            $token
        );
        $showResponse->assertStatus(404);

    }

    public function testRolesDeleteWithRoleManagerUser(): void
    {
        $token = $this->account_user_rolemanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;


        $deleteResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(200);

        $showResponse = $this->accountRolesShow(
            $this->main_account_slug,
            $role_id,
            $token
        );
        $showResponse->assertStatus(404);

    }

    public function testRolesRestoreWithVisitorUser(): void
    {
        $restoreResponse = $this->accountRolesRestore(
            $this->main_account_slug,
            $this->main_account_role_admin_id,
            $this->account_user_visitor_token
        );
        $restoreResponse->assertStatus(403);
    }

    public function testRolesRestoreWithUserManagerUser(): void
    {
        $restoreResponse = $this->accountRolesRestore(
            $this->main_account_slug,
            $this->main_account_role_admin_id,
            $this->account_user_usermanage_token
        );
        $restoreResponse->assertStatus(403);
    }

    public function testRolesRestoreWithRoleManagerUser(): void
    {
        $restoreResponse = $this->accountRolesRestore(
            $this->main_account_slug,
            $this->role_id_created_by_account_rolemanage_user,
            $this->account_user_rolemanage_token
        );
        $restoreResponse->assertStatus(200);

        $showResponse = $this->accountRolesShow(
            $this->main_account_slug,
            $this->role_id_created_by_account_rolemanage_user,
            $this->account_user_rolemanage_token
        );
        $showResponse->assertOk();
    }

    public function testRolesRestoreWithAdminUser(): void
    {
        $restoreResponse = $this->accountRolesRestore(
            $this->main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->account_user_admin_token_device_1
        );
        $restoreResponse->assertStatus(200);

        $showResponse = $this->accountRolesShow(
            $this->main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->account_user_admin_token_device_1
        );
        $showResponse->assertOk();
    }

    public function testRolesDeleteFlowWithRoleManagerUser(): void
    {
        $token = $this->account_user_rolemanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;


        $deleteResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(200);

        $rolesListResponse = $this->accountRolesList(
            $this->main_account_slug,
            $token
        );
        $rolesListResponse->assertOk();
        $this->assertArrayHasKey('total', $rolesListResponse);
        $this->assertEquals(5, $rolesListResponse['total']);

        $rolesListResponse = $this->accountRolesListArchived(
            $this->main_account_slug,
            $token
        );
        $rolesListResponse->assertOk();
        $this->assertArrayHasKey('total', $rolesListResponse);
        $this->assertEquals(1, $rolesListResponse['total']);

        $rolesListArchived = $this->accountRolesForceDelete(
            $this->main_account_slug,
            $role_id,
            $token
        );
        $rolesListArchived->assertOk();

        $rolesListResponse = $this->accountRolesList(
            $this->main_account_slug,
            $token
        );
        $rolesListResponse->assertOk();
        $this->assertArrayHasKey('total', $rolesListResponse);
        $this->assertEquals(5, $rolesListResponse['total']);

        $rolesListResponse = $this->accountRolesListArchived(
            $this->main_account_slug,
            $token
        );
        $rolesListResponse->assertOk();
        $this->assertArrayHasKey('total', $rolesListResponse);
        $this->assertEquals(0, $rolesListResponse['total']);

    }

    public function testRolesDeleteFlowWithAdminUser(): void
    {
        $token = $this->account_user_admin_token_device_1;
        $role_id = $this->role_id_created_by_account_admin_user;


        $deleteResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(200);

        $rolesListResponse = $this->accountRolesList(
            $this->main_account_slug,
            $token
        );
        $rolesListResponse->assertOk();
        $this->assertArrayHasKey('total', $rolesListResponse);
        $this->assertEquals(4, $rolesListResponse['total']);

        $rolesListResponse = $this->accountRolesListArchived(
            $this->main_account_slug,
            $token
        );
        $rolesListResponse->assertOk();
        $this->assertArrayHasKey('total', $rolesListResponse);
        $this->assertEquals(1, $rolesListResponse['total']);

        $rolesListArchived = $this->accountRolesForceDelete(
            $this->main_account_slug,
            $role_id,
            $token
        );
        $rolesListArchived->assertOk();

        $rolesListResponse = $this->accountRolesList(
            $this->main_account_slug,
            $token
        );

        $rolesListResponse->assertOk();
        $this->assertArrayHasKey('total', $rolesListResponse);
        $this->assertEquals(4, $rolesListResponse['total']);

        $rolesListResponse = $this->accountRolesListArchived(
            $this->main_account_slug,
            $token
        );
        $rolesListResponse->assertOk();
        $this->assertArrayHasKey('total', $rolesListResponse);
        $this->assertEquals(0, $rolesListResponse['total']);

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

    protected function accountRolesList(string $account_slug, string $token): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function accountRolesListArchived(string $account_slug, string $token): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles?archived=true",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function accountRolesCreate(string $account_slug, string $token, array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles",
            data: $data,
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function accountRolesShow(string $account_slug, string $roleId, string $token): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function accountRolesUpdate(string $account_slug, string $roleId, string $token, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId",
            data: $data,
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function accountRolesDelete(string $account_slug, string $roleId, string $token, array $data): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId",
            data: $data,
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function accountRolesForceDelete(string $account_slug, string $roleId, string $token): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId/force_delete",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function accountRolesRestore(string $account_slug, string $roleId, string $token): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId/restore",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

}
