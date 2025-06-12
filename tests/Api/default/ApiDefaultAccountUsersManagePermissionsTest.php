<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountUsersManagePermissionsTest extends TestCaseAuthenticated
{

    protected string $resource_slug = "users";

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

    protected string $account_user_admin_id {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_ID->value);
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

    public function testLoginCrossAccount(): void {}

    public function testLoginCrossTenant(): void {}

    public function testListWithAdminUser(): void {
        $listAdmin = $this->list(
            $this->main_account_slug,
            $this->account_user_admin_token_device_1
        );
        $listAdmin->assertOk();
    }

    public function testListWithUserManageUser(): void {
        $listUserManage = $this->list(
            $this->main_account_slug,
            $this->account_user_usermanage_token
        );
        $listUserManage->assertOk();
    }

    public function testListWithRoleManageUser(): void {
        $listRoleManage = $this->list(
            $this->main_account_slug,
            $this->account_user_rolemanage_token
        );
        $listRoleManage->assertOk();
    }

    public function testListWithVisitorUser(): void {
        $listVisitor = $this->list(
            $this->main_account_slug,
            $this->account_user_visitor_token
        );
        $listVisitor->assertStatus(403);
    }

    public function testListWithCrossAccount(): void {}

    public function testListWithCrossTenant(): void {}

    public function testCreateWithAdminUser(): void
    {
        $create = $this->create(
            $this->main_account_slug,
            $this->account_user_admin_token_device_1,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(201);
        $this->user_id_created_by_account_admin_user = $create->json()['data']['id'];

    }

    public function testCreateWithRoleManageUser(): void
    {
        $create = $this->create(
            $this->main_account_slug,
            $this->account_user_rolemanage_token,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(403);


    }

    public function testCreateWithUserManageUser(): void
    {
        $create = $this->create(
            $this->main_account_slug,
            $this->account_user_usermanage_token,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(201);
        $this->user_id_created_by_account_usermanage_user = $create->json()['data']['id'];

    }

    public function testCreateWithVisitorUser(): void
    {
        $create = $this->create(
            $this->main_account_slug,
            $this->account_user_visitor_token,
            [
                "name" => fake()->name,
                "emails" => [
                    fake()->email,
                    fake()->email,
                ],
                "password" => fake()->password(8),
                "role_id" => $this->main_account_role_visitor_id,
            ]
        );
        $create->assertStatus(403);

    }

    public function testCreateWithCrossAccount(): void {}

    public function testCreateWithCrossTenant(): void {}

    public function testShowWithAdminUser(): void
    {
        $show = $this->show(
            $this->main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->account_user_admin_token_device_1
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
            $this->main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->account_user_rolemanage_token
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
            $this->main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->account_user_usermanage_token
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
            $this->main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->account_user_visitor_token
        );
        $show->assertStatus(403);
    }

    public function testShowWithCrossAccount(): void {}

    public function testShowWithCrossTenant(): void {}

    public function testUpdateWithAdminUser(): void
    {
        $token = $this->account_user_admin_token_device_1;
        $user_id = $this->user_id_created_by_account_admin_user;

        $update = $this->update(
            $this->main_account_slug,
            $user_id,
            $token,
            [
                "name" => "Name by Admin Updated",
            ]
        );

        $update->assertStatus(200);

        $show = $this->show(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $show->assertOk();

        $this->assertEquals("Name by Admin Updated", $show->json()['data']['name']);

    }

    public function testUpdateWithRoleManagerUser(): void
    {
        $token = $this->account_user_rolemanage_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;

        $update = $this->update(
            $this->main_account_slug,
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
        $token = $this->account_user_usermanage_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;

        $update = $this->update(
            $this->main_account_slug,
            $user_id,
            $token,
            [
                "name" => "Name By Role Manager Updated",
            ]
        );
        $update->assertOk();

        $show = $this->show(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $show->assertOk();

        $this->assertEquals("Name By Role Manager Updated", $show->json()['data']['name']);

    }

    public function testUpdateWithVisitorUser(): void
    {
        $token = $this->account_user_visitor_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;

        $update = $this->update(
            $this->main_account_slug,
            $user_id,
            $token,
            [
                "name" => "Name By Role Manager Updated",
            ]
        );

        $update->assertStatus(403);

    }

    public function testUpdateWithCrossAccount(): void {}

    public function testUpdateWithCrossTenant(): void {}

    public function testDeleteWithUserManagerUser(): void
    {
        $token = $this->account_user_usermanage_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;


        $deleteResponse = $this->deleteItem(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $showResponse->assertStatus(404);

    }

    public function testDeleteWithVisitorUser(): void
    {
        $token = $this->account_user_visitor_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;


        $deleteResponse = $this->deleteItem(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(403);
    }

    public function testRolesDeleteOwnUser(): void
    {
        $token = $this->account_user_admin_token_device_1;
        $user_id = $this->account_user_admin_id;

        $deleteResponse = $this->deleteItem(
            $this->main_account_slug,
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

    public function testRolesDeleteWithAdminUser(): void
    {
        $token = $this->account_user_admin_token_device_1;
        $user_id = $this->user_id_created_by_account_admin_user;

        $deleteResponse = $this->deleteItem(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $showResponse->assertStatus(404);

    }

    public function testRolesDeleteWithRoleManagerUser(): void
    {
        $token = $this->account_user_rolemanage_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;


        $deleteResponse = $this->deleteItem(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $deleteResponse->assertStatus(403);

    }

    public function testRolesDeleteWithCrossAccount(): void {}

    public function testRolesDeleteWithCrossTenant(): void {}

    public function testRolesRestoreWithVisitorUser(): void
    {
        $restoreResponse = $this->restore(
            $this->main_account_slug,
            $this->main_account_role_admin_id,
            $this->account_user_visitor_token
        );
        $restoreResponse->assertStatus(403);
    }

    public function testRolesRestoreWithUserManagerUser(): void
    {
        $restoreResponse = $this->restore(
            $this->main_account_slug,
            $this->user_id_created_by_account_usermanage_user,
            $this->account_user_usermanage_token
        );
        $restoreResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->main_account_slug,
            $this->user_id_created_by_account_usermanage_user,
            $this->account_user_rolemanage_token
        );
        $showResponse->assertOk();

    }

    public function testRolesRestoreWithRoleManagerUser(): void
    {
        $restoreResponse = $this->restore(
            $this->main_account_slug,
            $this->user_id_created_by_account_usermanage_user,
            $this->account_user_rolemanage_token
        );
        $restoreResponse->assertStatus(403);

    }

    public function testRolesRestoreWithAdminUser(): void
    {
        $restoreResponse = $this->restore(
            $this->main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->account_user_admin_token_device_1
        );
        $restoreResponse->assertStatus(200);

        $showResponse = $this->show(
            $this->main_account_slug,
            $this->user_id_created_by_account_admin_user,
            $this->account_user_admin_token_device_1
        );
        $showResponse->assertOk();
    }

    public function testRolesRestoreWithCrossAccount(): void {}

    public function testRolesRestoreWithCrossTenant(): void {}

    public function testRolesDeleteFlowWithUserManagerUser(): void
    {
        $token = $this->account_user_usermanage_token;
        $user_id = $this->user_id_created_by_account_usermanage_user;


        $deleteResponse = $this->deleteItem(
            $this->main_account_slug,
            $user_id,
            $token,
        );
        $deleteResponse->assertStatus(200);

        $listResponse = $this->list(
            $this->main_account_slug,
            $token
        );
        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(5, $listResponse['total']);

        $listResponse = $this->listArchived(
            $this->main_account_slug,
            $token
        );
        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(1, $listResponse['total']);

        $forceDeleteResponse = $this->forceDelete(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $forceDeleteResponse->assertOk();

        $listResponse = $this->list(
            $this->main_account_slug,
            $token
        );
        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(5, $listResponse['total']);

        $istResponse = $this->listArchived(
            $this->main_account_slug,
            $token
        );
        $istResponse->assertOk();
        $this->assertArrayHasKey('total', $istResponse);
        $this->assertEquals(0, $istResponse['total']);

    }

    public function testRolesDeleteFlowWithAdminUser(): void
    {
        $token = $this->account_user_admin_token_device_1;
        $user_id = $this->user_id_created_by_account_admin_user;


        $deleteResponse = $this->deleteItem(
            $this->main_account_slug,
            $user_id,
            $token,
        );
        $deleteResponse->assertStatus(200);

        $listResponse = $this->list(
            $this->main_account_slug,
            $token
        );
        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(4, $listResponse['total']);

        $listResponse = $this->listArchived(
            $this->main_account_slug,
            $token
        );
        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(1, $listResponse['total']);

        $listArchived = $this->forceDelete(
            $this->main_account_slug,
            $user_id,
            $token
        );
        $listArchived->assertOk();

        $listResponse = $this->list(
            $this->main_account_slug,
            $token
        );

        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(4, $listResponse['total']);

        $listResponse = $this->listArchived(
            $this->main_account_slug,
            $token
        );
        $listResponse->assertOk();
        $this->assertArrayHasKey('total', $listResponse);
        $this->assertEquals(0, $listResponse['total']);

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

    protected function list(string $account_slug, string $token): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug",
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
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug?archived=true",
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
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug",
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
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId",
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
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId",
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
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId",
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
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId/force_delete",
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
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/$this->resource_slug/$resourceId/restore",
            headers: [
                'Authorization' => "Bearer $token",
                'Content-Type' => 'application/json'
            ]
        );
    }

}
