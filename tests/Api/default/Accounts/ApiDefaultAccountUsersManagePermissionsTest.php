<?php

namespace Tests\Api\default\Accounts;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountUsersManagePermissionsTest extends TestCaseAuthenticated
{

    protected string $resource_slug = "users";

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
            return $this->getGlobal(TenantSecondary::SUBDOMAIN->value);
        }
    }

    protected string $tenant_1_subdomain {
        get {
            return $this->getGlobal(TenantSecondary::SUBDOMAIN->value);
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

    protected string $user_id_created_by_account_admin_user {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::USER_ID_CREATED_BY_ACCOUNT_ADMIN_USER->value, $value);
            $this->user_id_created_by_account_admin_user = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::USER_ID_CREATED_BY_ACCOUNT_ADMIN_USER->value);
        }
    }

    protected string $user_id_created_by_account_usermanage_user {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::USER_ID_CREATED_BY_ACCOUNT_USERMANAGE_USER->value, $value);
            $this->user_id_created_by_account_usermanage_user = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::USER_ID_CREATED_BY_ACCOUNT_USERMANAGE_USER->value);
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

    protected string $tenant_2_account_user_admin_id {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_USER_ADMIN_ID->value);
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
        $this->tenant_2_account_user_admin_token_device_1 = $responseUserAdmin->json()['data']['token'];
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
    }

    public function testLoginCrossTenant(): void {
        $responseUserVisitor = $this->userLoginTenant1([
                "email" => $this->tenant_1_account_user_admin_email,
                "password" => $this->tenant_1_account_user_admin_password,
                "device_name" => "test",
            ]
        );
        $responseUserVisitor->assertStatus(200);
    }

    public function testListWithAdminUser(): void {
        $listAdmin = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_admin_token_device_1
        );
        $listAdmin->assertOk();
    }

    public function testListWithUserManageUser(): void {
        $listUserManage = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_usermanage_token
        );
        $listUserManage->assertOk();
    }

    public function testListWithRoleManageUser(): void {
        $listRoleManage = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_rolemanage_token
        );
        $listRoleManage->assertOk();
    }

    public function testListWithVisitorUser(): void {
        $listVisitor = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_visitor_token
        );
        $listVisitor->assertStatus(403);
    }

    public function testListWithCrossAccount(): void {
        $listVisitor = $this->list(
            $this->tenant_2_main_account_slug,
            $this->secondary_account_user_admin_token
        );
        $listVisitor->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $listVisitor->json()['message']);
    }

    public function testListWithCrossTenant(): void {
        $listVisitor = $this->list(
            $this->tenant_2_main_account_slug,
            $this->tenant_1_account_user_admin_token
        );
        $listVisitor->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $listVisitor->json()['message']);
    }

    public function testCreateWithAdminUser(): void
    {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_admin_token_device_1,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->tenant_2_main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(201);
        $this->user_id_created_by_account_admin_user = $create->json()['data']['id'];

    }

    public function testCreateWithRoleManageUser(): void
    {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_rolemanage_token,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->tenant_2_main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(403);


    }

    public function testCreateWithUserManageUser(): void
    {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_usermanage_token,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->tenant_2_main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(201);
        $this->user_id_created_by_account_usermanage_user = $create->json()['data']['id'];

    }

    public function testCreateWithVisitorUser(): void
    {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_account_user_visitor_token,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->tenant_2_main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(403);

    }

    public function testCreateWithCrossAccount(): void {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->secondary_account_user_admin_token,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->tenant_2_main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $create->json()['message']);
    }

    public function testCreateWithCrossTenant(): void {
        $create = $this->create(
            $this->tenant_2_main_account_slug,
            $this->tenant_1_account_user_admin_token,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->tenant_2_main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $create->json()['message']);
    }

    public function testShowWithAdminUser(): void
    {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->tenant_2_account_user_admin_token_device_1
        );
        $show->assertOk();
        $show->assertJsonStructure([
            "data" => [
                "name",
                "emails",
                "created_at",
            ],
        ]);
    }

    public function testShowWithRoleManagerUser(): void
    {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->tenant_2_account_user_rolemanage_token
        );
        $show->assertOk();
        $show->assertJsonStructure([
            "data" => [
                "name",
                "emails",
                "created_at",
            ],
        ]);
    }

    public function testShowWithUserManagerUser(): void
    {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->tenant_2_account_user_usermanage_token
        );
        $show->assertOk();
        $show->assertJsonStructure([
            "data" => [
                "name",
                "emails",
                "created_at",
            ],
        ]);
    }

    public function testShowWithVisitorUser(): void
    {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->tenant_2_account_user_visitor_token
        );
        $show->assertStatus(403);
    }

    public function testShowWithCrossAccount(): void {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->secondary_account_user_admin_token
        );
        $show->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $show->json()['message']);
    }

    public function testShowWithCrossTenant(): void {
        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->tenant_1_account_user_admin_token
        );
        $show->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $show->json()['message']);
    }

    public function testUpdateWithAdminUser(): void
    {
        $token = $this->tenant_2_account_user_admin_token_device_1;
        $user_id = $this->user_id_created_by_account_admin_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token,
            [
                "name" => "Name by Admin Updated",
            ]
        );

        $update->assertStatus(200);

        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $show->assertOk();

        $this->assertEquals("Name by Admin Updated", $show->json()['data']['name']);

    }

    public function testUpdateWithRoleManagerUser(): void
    {
        $token = $this->tenant_2_account_user_rolemanage_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token,
            [
                "name" => "Name By Role Manager Updated",
            ]
        );

        $update->assertStatus(403);

    }

    public function testUpdateWithUserManagerUser(): void
    {
        $token = $this->tenant_2_account_user_usermanage_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token,
            [
                "name" => "Name By Role Manager Updated",
            ]
        );
        $update->assertOk();

        $show = $this->show(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $show->assertOk();

        $this->assertEquals("Name By Role Manager Updated", $show->json()['data']['name']);

    }

    public function testUpdateWithVisitorUser(): void
    {
        $token = $this->tenant_2_account_user_visitor_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token,
            [
                "name" => "Name By Role Manager Updated",
            ]
        );

        $update->assertStatus(403);

    }

    public function testUpdateWithCrossAccount(): void {
        $token = $this->secondary_account_user_admin_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token,
            [
                "name" => "Name By Role Manager Updated",
            ]
        );

        $update->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $update->json()['message']);
    }

    public function testUpdateWithCrossTenant(): void {
        $token = $this->tenant_1_account_user_admin_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;

        $update = $this->update(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token,
            [
                "name" => "Name By Role Manager Updated",
            ]
        );

        $update->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $update->json()['message']);
    }

    public function testDeleteWithUserManagerUser(): void
    {
        $token = $this->tenant_2_account_user_usermanage_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $showResponse->assertStatus(404);

    }

    public function testDeleteWithVisitorUser(): void
    {
        $token = $this->tenant_2_account_user_visitor_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(403);
    }

    public function testDeleteOwnUser(): void
    {
        $token = $this->tenant_2_account_user_admin_token_device_1;
        $user_id = $this->tenant_2_account_user_admin_id;

        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(422);

        $deleteResponse->assertJsonStructure([
            "message",
            "errors" => [
                "user_id"
            ]
        ]);

    }

    public function testDeleteWithAdminUser(): void
    {
        $token = $this->tenant_2_account_user_admin_token_device_1;
        $user_id = $this->user_id_created_by_account_admin_user;

        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $showResponse->assertStatus(404);

    }

    public function testDeleteWithRoleManagerUser(): void
    {
        $token = $this->tenant_2_account_user_rolemanage_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(403);

    }

    public function testDeleteWithCrossAccount(): void {
        $token = $this->secondary_account_user_admin_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $deleteResponse->json()['message']);
    }

    public function testDeleteWithCrossTenant(): void {
        $token = $this->tenant_1_account_user_admin_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;


        $deleteResponse = $this->deleteItem(
            $this->tenant_2_main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $deleteResponse->json()['message']);
    }

    public function testRestoreWithVisitorUser(): void
    {
        $restoreResponse = $this->restore(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_main_account_role_admin_id,
            $this->tenant_2_account_user_visitor_token
        );
        $restoreResponse->assertStatus(403);
    }

    public function testRestoreWithCrossAccount(): void {
        $restoreResponse = $this->restore(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_main_account_role_admin_id,
            $this->secondary_account_user_admin_token
        );
        $restoreResponse->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $restoreResponse->json()['message']);
    }

    public function testRestoreWithCrossTenant(): void {
        $restoreResponse = $this->restore(
            $this->tenant_2_main_account_slug,
            $this->tenant_2_main_account_role_admin_id,
            $this->tenant_1_account_user_admin_token
        );
        $restoreResponse->assertStatus(401);
        $this->assertEquals("Unauthenticated.", $restoreResponse->json()['message']);
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
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug",
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
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug?archived=true",
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
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug",
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
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId",
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
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId",
            data: $data,
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

    protected function deleteItem(string $account_slug, string $resourceId, string $token): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId",
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
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId/force_delete",
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
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId/restore",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

}
