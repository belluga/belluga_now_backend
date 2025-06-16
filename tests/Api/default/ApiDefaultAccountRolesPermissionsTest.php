<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountRolesPermissionsTest extends TestCaseAuthenticated
{

    protected string $resource_slug = "roles";

    protected string $tenant_2_base_api_url {
        get {
            return "http://{$this->tenant_2_subdomain}.localhost/api/";
        }
    }

    protected string $tenant_1_base_api_url {
        get {
            return "http://{$this->tenant_1_subdomain}.localhost/api/";
        }
    }

    protected string $tenant_2_subdomain {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SUBDOMAIN->value);
        }
    }

    protected string $tenant_1_subdomain {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_1_SUBDOMAIN->value);
        }
    }

    protected string $tenant_2_main_account_slug {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_MAIN_ACCOUNT_SLUG->value);
        }
    }

    protected string $tenant_1_account_user_admin_email {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_1_ACCOUNT_USER_ADMIN_EMAIL->value);
        }
    }

    protected string $tenant_1_account_user_admin_password {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_1_ACCOUNT_USER_ADMIN_PASSWORD->value);
        }
    }

    protected string $tenant_1_account_user_admin_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_1_ACCOUNT_USER_ADMIN_TOKEN->value, $value);
            $this->tenant_1_account_user_admin_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_1_ACCOUNT_USER_ADMIN_TOKEN->value);
        }
    }

    protected string $tenant_2_account_user_rolemanage_email {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_ROLEMANAGE_EMAIL->value);
        }
    }

    protected string $tenant_2_account_user_rolemanage_password {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_ROLEMANAGE_PASSWORD->value);
        }
    }

    protected string $tenant_2_account_user_rolemanage_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_ROLEMANAGE_TOKEN->value, $value);
            $this->tenant_2_account_user_rolemanage_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_ROLEMANAGE_TOKEN->value);
        }
    }

    protected string $tenant_2_account_user_usermanage_email {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_USERMANAGE_EMAIL->value);
        }
    }

    protected string $tenant_2_account_user_usermanage_password {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_USERMANAGE_PASSWORD->value);
        }
    }

    protected string $tenant_2_account_user_usermanage_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_USERMANAGE_TOKEN->value, $value);
            $this->tenant_2_account_user_usermanage_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_USERMANAGE_TOKEN->value);
        }
    }

    protected string $tenant_2_account_user_admin_email_1 {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_ADMIN_EMAIL_1->value);
        }
    }

    protected string $tenant_2_account_user_admin_password {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_ADMIN_PASSWORD->value);
        }
    }

    protected string $tenant_2_account_user_admin_token_device_1 {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN->value, $value);
            $this->tenant_2_account_user_admin_token_device_1 = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN->value);
        }
    }

    protected string $tenant_2_account_user_visitor_email {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_VISITOR_EMAIL->value);
        }
    }

    protected string $tenant_2_account_user_visitor_password {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_VISITOR_PASSWORD->value);
        }
    }

    protected string $tenant_2_account_user_visitor_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_VISITOR_TOKEN->value, $value);
            $this->tenant_2_account_user_visitor_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_VISITOR_TOKEN->value);
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

    protected string $tenant_2_main_account_role_admin_id {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_ADMIN_ID->value);
        }
    }

    protected string $tenant_2_main_account_role_usermanage_id {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_USERMANAGE_ID->value);
        }
    }

    protected string $tenant_2_main_account_role_rolemanage_id {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_ROLEMANAGE_ID->value);
        }
    }

    protected string $tenant_2_main_account_role_visitor_id {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_VISITOR_ID->value);
        }
    }

    protected string $secondary_account_user_admin_name {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_USER_ADMIN_NAME->value);
        }
    }

    protected string $secondary_account_user_admin_email {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_USER_ADMIN_EMAIL->value);
        }
    }

    protected string $secondary_account_user_admin_password {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_USER_ADMIN_PASSWORD->value);
        }
    }

    protected string $secondary_account_user_admin_id {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_USER_ADMIN_ID->value);
        }
    }

    protected string $secondary_account_user_admin_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_ACCOUNT_USER_ADMIN_TOKEN->value, $value);
            $this->secondary_account_user_admin_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_USER_ADMIN_TOKEN->value);
        }
    }

    public function testLoginAdminSuccess(): void {
        $responseUserAdmin = $this->userLogin([
                "email" => $this->tenant_2_account_user_admin_email_1,
                "password" => $this->tenant_2_account_user_admin_password,
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
                "email" => $this->tenant_2_account_user_usermanage_email,
                "password" => $this->tenant_2_account_user_usermanage_password,
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

        $this->tenant_2_account_user_usermanage_token = $responseUserUserManage->json()['data']['token'];
    }

    public function testLoginRoleManagerSuccess(): void {
        $responseUserRoleManage = $this->userLogin([
                "email" => $this->tenant_2_account_user_rolemanage_email,
                "password" => $this->tenant_2_account_user_rolemanage_password,
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

        $this->tenant_2_account_user_rolemanage_token = $responseUserRoleManage->json()['data']['token'];
    }

    public function testLoginVisitorSuccess(): void {
        $responseUserVisitor = $this->userLogin([
                "email" => $this->tenant_2_account_user_visitor_email,
                "password" => $this->tenant_2_account_user_visitor_password,
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

        $this->tenant_2_account_user_visitor_token = $responseUserVisitor->json()['data']['token'];
    }

    public function testLoginCrossAccount(): void {
        $responseUserVisitor = $this->userLogin([
                "email" => $this->secondary_account_user_admin_email,
                "password" => $this->secondary_account_user_admin_password,
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

        $this->secondary_account_user_admin_token = $responseUserVisitor->json()['data']['token'];
    }

    public function testLoginCrossTenant(): void {

        $responseUserVisitor = $this->userLogin([
                "email" => $this->tenant_1_account_user_admin_email,
                "password" => $this->tenant_1_account_user_admin_password,
                "device_name" => "test",
            ]
        );
        $responseUserVisitor->assertStatus(403);

        $responseUserVisitor = $this->userLoginTenant1([
                "email" => $this->tenant_1_account_user_admin_email,
                "password" => $this->tenant_1_account_user_admin_password,
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

        $this->tenant_1_account_user_admin_token = $responseUserVisitor->json()['data']['token'];

    }

    public function testRolesListWithAdminUser(): void {
        $listAdmin = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_admin_token_device_1
        );

        $listAdmin->assertOk();
    }

    public function testRolesListWithUserManageUser(): void {
        $listUserManage = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_usermanage_token
        );
        $listUserManage->assertOk();
    }

    public function testRolesListWithRoleManageUser(): void {
        $listRoleManage = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_rolemanage_token
        );
        $listRoleManage->assertOk();
    }

    public function testRolesListWithVisitorUser(): void {
        $listVisitor = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_visitor_token
        );
        $listVisitor->assertStatus(403);
    }

    public function testRolesListWithCrossAccount(): void {
        $listVisitor = $this->list(
            $this->tenant_2_main_account_slug,
            $this->secondary_account_user_admin_token
        );

        $listVisitor->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $listVisitor->json()['message']);

    }

    public function testRolesListWithCrossTenant(): void {
        $listVisitor = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_1_account_user_admin_token
        );
        $listVisitor->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $listVisitor->json()['message']);
    }

    public function testRolesCreateWithAdminUser(): void
    {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_admin_token_device_1,
            [
                "name" => "Role By Admin",
                "description" => "Role for testing purposes created by Account Admin User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $create->assertStatus(201);
        $this->role_id_created_by_account_admin_user = $create->json()['data']['id'];

    }

    public function testRolesCreateWithRoleManageUser(): void
    {
        $reate = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_rolemanage_token,
            [
                "name" => "Role By Role Manager",
                "description" => "Role for testing purposes created by Role Manager User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $reate->assertStatus(201);
        $this->role_id_created_by_account_rolemanage_user = $reate->json()['data']['id'];

    }

    public function testRolesCreateWithUserManageUser(): void
    {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_usermanage_token,
            [
                "name" => "Role By User Manager",
                "description" => "Role for testing purposes created by User Manager User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $create->assertStatus(403);

    }

    public function testRolesCreateWithVisitorUser(): void
    {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_visitor_token,
            [
                "name" => "Role By Visitor",
                "description" => "Role for testing purposes created by Visitor User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $create->assertStatus(403);

    }

    public function testRolesCreateWithCrossAccount(): void {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->secondary_account_user_admin_token,
            [
                "name" => "Role By Visitor",
                "description" => "Role for testing purposes created by Visitor User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $create->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $create->json()['message']);
    }

    public function testRolesCreateWithCrossTenant(): void {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_1_account_user_admin_token,
            [
                "name" => "Role By Visitor",
                "description" => "Role for testing purposes created by Visitor User",
                "permissions" => ["user:view", "user:create", "role:view"],
            ]
        );
        $create->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $create->json()['message']);
    }

    public function testRolesShowWithAdminUser(): void
    {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->tenant_2_account_user_admin_token_device_1
        );
        $show->assertOk();
        $show->assertJsonStructure([
            "data" => [
                "name",
                "permissions",
                "created_at",
            ],
        ]);
    }

    public function testRolesShowWithRoleManagerUser(): void
    {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->tenant_2_account_user_rolemanage_token
        );
        $show->assertOk();
        $show->assertJsonStructure([
            "data" => [
                "name",
                "permissions",
                "created_at",
            ],
        ]);
    }

    public function testRolesShowWithUserManagerUser(): void
    {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->tenant_2_account_user_usermanage_token
        );
        $show->assertStatus(200);
    }

    public function testRolesShowWithVisitorUser(): void
    {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->tenant_2_account_user_visitor_token
        );
        $show->assertStatus(403);
    }

    public function testRolesShowWithCrossAccount(): void {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->secondary_account_user_admin_token
        );
        $show->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $show->json()['message']);
    }

    public function testRolesShowWithCrossTenant(): void {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->tenant_1_account_user_admin_token
        );
        $show->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $show->json()['message']);
    }

    public function testRolesUpdateWithAdminUser(): void
    {
        $token = $this->tenant_2_account_user_admin_token_device_1;
        $role_id = $this->role_id_created_by_account_admin_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By Admin Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $update->assertStatus(200);

        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token
        );
        $show->assertOk();

        $this->assertEquals("Role By Admin Updated", $show->json()['data']['name']);
        $this->assertEquals(
            ["user:view", "user:create", "role:view", "role:create"],
            $show->json()['data']['permissions']
        );
    }

    public function testRolesUpdateWithRoleManagerUser(): void
    {
        $token = $this->tenant_2_account_user_rolemanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By Role Manager Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $update->assertStatus(200);

        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token
        );
        $show->assertOk();

        $this->assertEquals("Role By Role Manager Updated", $show->json()['data']['name']);
        $this->assertEquals(
            ["user:view", "user:create", "role:view", "role:create"],
            $show->json()['data']['permissions']
        );
    }

    public function testRolesUpdateWithUserManagerUser(): void
    {
        $token = $this->tenant_2_account_user_usermanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By User Manager Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $update->assertStatus(403);

    }

    public function testRolesUpdateWithVisitorUser(): void
    {
        $token = $this->tenant_2_account_user_visitor_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By User Manager Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $update->assertStatus(403);

    }

    public function testRolesUpdateWithCrossAccount(): void {
        $token = $this->secondary_account_user_admin_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By User Manager Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $update->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $update->json()['message']);
    }

    public function testRolesUpdateWithCrossTenant(): void {
        $token = $this->tenant_1_account_user_admin_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "name" => "Role By User Manager Updated",
                "permissions" => ["user:view", "user:create", "role:view", "role:create"],
            ]
        );

        $update->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $update->json()['message']);
    }

    public function testRolesDeleteWithUserManagerUser(): void
    {
        $token = $this->tenant_2_account_user_usermanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->tenant_2_main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(403);
    }

    public function testRolesDeleteWithVisitorUser(): void
    {
        $token = $this->tenant_2_account_user_visitor_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->tenant_2_main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(403);
    }

    public function testRolesDeleteWithSameRoleAsBackground(): void
    {
        $token = $this->tenant_2_account_user_admin_token_device_1;
        $role_id = $this->role_id_created_by_account_admin_user;

        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
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
        $token = $this->tenant_2_account_user_admin_token_device_1;
        $role_id = $this->role_id_created_by_account_admin_user;

        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->tenant_2_main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token
        );
        $showResponse->assertStatus(404);

    }

    public function testRolesDeleteWithCrossAccount(): void {
        $token = $this->secondary_account_user_admin_token;
        $role_id = $this->role_id_created_by_account_admin_user;

        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->tenant_2_main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $deleteResponse->json()['message']);
    }

    public function testRolesDeleteWithCrossTenant(): void {
        $token = $this->tenant_1_account_user_admin_token;
        $role_id = $this->role_id_created_by_account_admin_user;

        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->tenant_2_main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $deleteResponse->json()['message']);
    }

    public function testRolesDeleteWithRoleManagerUser(): void
    {
        $token = $this->tenant_2_account_user_rolemanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->tenant_2_main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token
        );
        $showResponse->assertStatus(404);

    }

    public function testRolesRestoreWithVisitorUser(): void
    {
        $restoreResponse = $this->restore(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_main_account_role_admin_id,
            $this->tenant_2_account_user_visitor_token
        );
        $restoreResponse->assertStatus(403);
    }

    public function testRolesRestoreWithUserManagerUser(): void
    {
        $restoreResponse = $this->restore(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_main_account_role_admin_id,
            $this->tenant_2_account_user_usermanage_token
        );
        $restoreResponse->assertStatus(403);
    }

    public function testRolesRestoreWithRoleManagerUser(): void
    {
        $restoreResponse = $this->restore(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_rolemanage_user,
            $this->tenant_2_account_user_rolemanage_token
        );
        $restoreResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_rolemanage_user,
            $this->tenant_2_account_user_rolemanage_token
        );
        $showResponse->assertOk();
    }

    public function testRolesRestoreWithCrossAccount(): void {
        $restoreResponse = $this->restore(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->secondary_account_user_admin_token
        );
        $restoreResponse->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $restoreResponse->json()['message']);
    }

    public function testRolesRestoreWithCrossTenant(): void {
        $restoreResponse = $this->restore(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->tenant_1_account_user_admin_token
        );
        $restoreResponse->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $restoreResponse->json()['message']);
    }

    public function testRolesRestoreWithAdminUser(): void
    {
        $restoreResponse = $this->restore(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->account_user_admin_token_device_1
        );
        $restoreResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->tenant_2_main_account_slug,
            $this->role_id_created_by_account_admin_user,
            $this->account_user_admin_token_device_1
        );
        $showResponse->assertOk();
    }

    public function testRolesDeleteFlowWithRoleManagerUser(): void
    {
        $token = $this->tenant_2_account_user_rolemanage_token;
        $role_id = $this->role_id_created_by_account_rolemanage_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->tenant_2_main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(200);

        $listResponse = $this->listArchived(
            $this->tenant_2_main_account_slug,
            $token
        );
        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(1, $listResponse['total']);

        $forceDeleteResponse = $this->forceDelete(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token
        );
        $forceDeleteResponse->assertOk();

        $showResponse = $this->show(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token
        );
        $showResponse->assertStatus(404);

        $istResponse = $this->listArchived(
            $this->tenant_2_main_account_slug,
            $token
        );
        $istResponse->assertOk();
        $this->assertArrayHasKey('total', $istResponse);
        $this->assertEquals(0, $istResponse['total']);

    }

    public function testRolesDeleteFlowWithAdminUser(): void
    {
        $token = $this->account_user_admin_token_device_1;
        $role_id = $this->role_id_created_by_account_admin_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token,
            [
                "role_id" => $this->tenant_2_main_account_role_visitor_id
            ]
        );
        $deleteResponse->assertStatus(200);


        $listResponse = $this->listArchived(
            $this->tenant_2_main_account_slug,
            $token
        );
        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(1, $listResponse['total']);


        $listArchived = $this->forceDelete(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token
        );
        $listArchived->assertOk();


        $showResponse = $this->show(
            $this->tenant_2_main_account_slug,
            $role_id,
            $token
        );
        $showResponse->assertStatus(404);


        $listResponse = $this->listArchived(
            $this->tenant_2_main_account_slug,
            $token
        );
        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(0, $listResponse['total']);

    }

    public function testLogoutUsers(): void {
        $responseUserAdmin = $this->userLogout([
                "device_name" => "test_1",
            ], $this->tenant_2_account_user_admin_token_device_1
        );
        $responseUserAdmin->assertStatus(200);
        $this->tenant_2_account_user_admin_token_device_1 = "";

        $responseUserUserManage = $this->userLogout([
                "device_name" => "test",
            ],
            $this->tenant_2_account_user_usermanage_token
        );
        $responseUserUserManage->assertStatus(200);
        $this->tenant_2_account_user_usermanage_token = "";

        $responseUserRoleManage = $this->userLogout([
                "device_name" => "test",
            ],
            $this->tenant_2_account_user_rolemanage_token
        );
        $responseUserRoleManage->assertStatus(200);
        $this->tenant_2_account_user_rolemanage_token = "";
    }

    protected function userLogin(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: $this->tenant_2_base_api_url."auth/login",
            data: $data
        );
    }

    protected function userLoginTenant1(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: $this->tenant_1_base_api_url."auth/login",
            data: $data
        );
    }

    protected function userLogout(array $data, string $token): TestResponse {
        return $this->json(
            method: 'post',
            uri: $this->tenant_2_base_api_url."auth/logout",
            data: $data,
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function list(string $account_slug, string $token): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: $this->tenant_2_base_api_url."accounts/$account_slug/$this->resource_slug",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function listArchived(string $account_slug, string $token): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: $this->tenant_2_base_api_url."accounts/$account_slug/$this->resource_slug?archived=true",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function create(string $account_slug, string $token, array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: $this->tenant_2_base_api_url."accounts/$account_slug/$this->resource_slug",
            data: $data,
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function show(string $account_slug, string $resourceId, string $token): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: $this->tenant_2_base_api_url."accounts/$account_slug/$this->resource_slug/$resourceId",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function update(string $account_slug, string $resourceId, string $token, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: $this->tenant_2_base_api_url."accounts/$account_slug/$this->resource_slug/$resourceId",
            data: $data,
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function deleteItem(string $account_slug, string $resourceId, string $token, array $data): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: $this->tenant_2_base_api_url."accounts/$account_slug/$this->resource_slug/$resourceId",
            data: $data,
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function forceDelete(string $account_slug, string $resourceId, string $token): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: $this->tenant_2_base_api_url."accounts/$account_slug/$this->resource_slug/$resourceId/force_delete",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function restore(string $account_slug, string $resourceId, string $token): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: $this->tenant_2_base_api_url."accounts/$account_slug/$this->resource_slug/$resourceId/restore",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

}
