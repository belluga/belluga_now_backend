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

    protected string $account_user_admin_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN->value, $value);
            $this->account_user_admin_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ADMIN_DEVICE_1_TOKEN->value);
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

    protected string $account_user_usermanage_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_TOKEN->value, $value);
            $this->account_user_usermanage_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_USERMANAGE_TOKEN->value);
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

    protected string $account_user_rolemanage_token {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_TOKEN->value, $value);
            $this->account_user_rolemanage_token = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_ROLEMANAGE_TOKEN->value);
        }
    }

    protected string $account_user_to_delete_name {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_NAME->value, $value);
            $this->account_user_to_delete_name = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_NAME->value);
        }
    }

    protected string $account_user_to_delete_email {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_EMAIL->value, $value);
            $this->account_user_to_delete_email = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_EMAIL->value);
        }
    }

    protected string $account_user_to_delete_password {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_PASSWORD->value, $value);
            $this->account_user_to_delete_password = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_PASSWORD->value);
        }
    }

    protected string $account_user_to_delete_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_ID->value, $value);
            $this->account_user_to_delete_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_ID->value);
        }
    }

    protected string $account_user_to_delete_email_2 {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_EMAIL_2->value, $value);
            $this->account_user_to_delete_email_2 = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_EMAIL_2->value);
        }
    }

    protected string $account_user_to_delete_email_3 {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_EMAIL_3->value, $value);
            $this->account_user_to_delete_email_3 = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_TODELETE_EMAIL_3->value);
        }
    }

    protected string $account_user_visitor_name {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_NAME->value, $value);
            $this->account_user_visitor_name = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_NAME->value);
        }
    }

    protected string $account_user_visitor_email {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_EMAIL->value, $value);
            $this->account_user_visitor_email = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_EMAIL->value);
        }
    }

    protected string $account_user_visitor_password {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_PASSWORD->value, $value);
            $this->account_user_visitor_password = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_PASSWORD->value);
        }
    }

    protected string $account_user_visitor_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_ID->value, $value);
            $this->account_user_visitor_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_USER_VISITOR_ID->value);
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

    public function testAccountUserCreation(): void {
        $this->account_user_admin_name = fake()->name();
        $this->account_user_admin_email_1 = fake()->email();
        $this->account_user_admin_email_2 = fake()->email();
        $this->account_user_admin_password = fake()->password(8);

        $response = $this->accountUserCreate([
            "name" => $this->account_user_admin_name,
            "emails" => [
                $this->account_user_admin_email_1,
                $this->account_user_admin_email_2,
            ],
            "password" => $this->account_user_admin_password,
            "password_confirmation" => $this->account_user_admin_password,
            "role_id" => $this->main_account_role_admin_id,

        ]);

        $response->assertStatus(201);
        $this->account_user_admin_id = $response->json()['data']["id"];


        $this->account_user_usermanage_name = fake()->name();
        $this->account_user_usermanage_email = fake()->email();
        $this->account_user_usermanage_password = fake()->password(8);

        $response = $this->accountUserCreate([
            "name" => $this->account_user_usermanage_name,
            "emails" => [
                $this->account_user_usermanage_email,
            ],
            "password" => $this->account_user_usermanage_password,
            "password_confirmation" => $this->account_user_usermanage_password,
            "role_id" => $this->main_account_role_usermanage_id,

        ]);

        $response->assertStatus(201);
        $this->account_user_usermanage_id = $response->json()['data']["id"];


        $this->account_user_rolemanage_name = fake()->name();
        $this->account_user_rolemanage_email = fake()->email();
        $this->account_user_rolemanage_password = fake()->password(8);

        $response = $this->accountUserCreate([
            "name" => $this->account_user_rolemanage_name,
            "emails" => [
                $this->account_user_rolemanage_email,
            ],
            "password" => $this->account_user_rolemanage_password,
            "password_confirmation" => $this->account_user_rolemanage_password,
            "role_id" => $this->main_account_role_rolemanage_id,

        ]);

        $response->assertStatus(201);
        $this->account_user_rolemanage_id = $response->json()['data']["id"];


        $this->account_user_to_delete_name = fake()->name();
        $this->account_user_to_delete_email = fake()->email();
        $this->account_user_to_delete_password = fake()->password(8);

        $response = $this->accountUserCreate([
            "name" => $this->account_user_to_delete_name,
            "emails" => [
                $this->account_user_to_delete_email,
            ],
            "password" => $this->account_user_to_delete_password,
            "password_confirmation" => $this->account_user_to_delete_password,
            "role_id" => $this->main_account_role_visitor_id,

        ]);

        $response->assertStatus(201);
        $this->account_user_to_delete_id = $response->json()['data']["id"];


        $this->account_user_visitor_name = fake()->name();
        $this->account_user_visitor_email = fake()->email();
        $this->account_user_visitor_password = fake()->password(8);

        $response = $this->accountUserCreate([
            "name" => $this->account_user_visitor_name,
            "emails" => [
                $this->account_user_visitor_email,
            ],
            "password" => $this->account_user_visitor_password,
            "password_confirmation" => $this->account_user_visitor_password,
            "role_id" => $this->main_account_role_visitor_id,
        ]);

        $response->assertStatus(201);
        $this->account_user_visitor_id = $response->json()['data']["id"];
    }

    public function testAccountUsersList(): void {

        $accountUserList = $this->accountUsersList();
        $accountUserList->assertStatus(200);

        $this->assertArrayHasKey('total', $accountUserList->json());
        $this->equalTo(4, $accountUserList->json()['total']);

    }

    public function testAccountUserShow(): void {
        $accountUserShow = $this->accountUserShow($this->account_user_to_delete_id);
        $accountUserShow->assertStatus(200);

        $accountUserShow->assertJsonStructure([
           "data" => [
               "id",
               "name",
               "emails",
           ]
        ]);
    }

    public function testAccountUserUpdate(): void {
        $roleUpdate = $this->accountUserUpdate(
            $this->account_user_to_delete_id,
            [
                "name" => "Updated Account Name",
            ]
        );

        $roleUpdate->assertStatus(200);

        $rolesShow = $this->accountUserShow($this->account_user_to_delete_id);
        $rolesShow->assertOk();

        $this->assertEquals("Updated Account Name", $rolesShow->json()['data']['name']);

    }

    public function testAccountUserAddEmail(): void {

        $this->account_user_to_delete_email_2 = fake()->email();
        $this->account_user_to_delete_email_3 = fake()->email();

        $roleUpdate = $this->accountUserAddEmails(
            $this->account_user_to_delete_id,
            [
                "emails" => [
                    $this->account_user_to_delete_email_2,
                    $this->account_user_to_delete_email_3,
                ],
            ]
        );

        $roleUpdate->assertStatus(200);

        $rolesShow = $this->accountUserShow($this->account_user_to_delete_id);
        $rolesShow->assertOk();

        $this->assertCount(3, $rolesShow->json()['data']['emails']);
        $this->assertEquals($this->account_user_to_delete_email_2, $rolesShow->json()['data']['emails'][1]);
        $this->assertEquals($this->account_user_to_delete_email_3, $rolesShow->json()['data']['emails'][2]);
    }

    public function testAccountUserRemoveEmail(): void
    {

        $addEmailsResponse = $this->accountUserRemoveEmails(
            $this->account_user_to_delete_id,
            [
                "emails" => [
                    $this->account_user_to_delete_email_2,
                    $this->account_user_to_delete_email_3,
                ],
            ]
        );

        $addEmailsResponse->assertStatus(200);

        $accountUserShow = $this->accountUserShow($this->account_user_to_delete_id);
        $accountUserShow->assertOk();

        $this->assertCount(1, $accountUserShow->json()['data']['emails']);
        $this->assertNotEquals($this->account_user_to_delete_email_2, $accountUserShow->json()['data']['emails'][0]);
        $this->assertNotEquals($this->account_user_to_delete_email_3, $accountUserShow->json()['data']['emails'][0]);
    }

    public function testAccountDelete(): void {
        $rolesList = $this->accountUsersList();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(4, $responseData['total']);

        $deleteResponse = $this->accountUserDelete($this->account_user_to_delete_id);
        $deleteResponse->assertStatus(200);

        $rolesList = $this->accountUsersList();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(3, $responseData['total']);

        $rolesList = $this->accountUsersListArchived();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(1, $responseData['total']);

        $showDeleted = $this->accountUserShow($this->account_user_to_delete_id);
        $showDeleted->assertStatus(404);
    }

    public function testAccountRestore(): void {
        $restoreResponse = $this->accountUserRestore($this->account_user_to_delete_id);
        $restoreResponse->assertStatus(200);

        // Should be able to get the restored role
        $showResponse = $this->accountUserShow($this->account_user_to_delete_id);
        $showResponse->assertOk();

        $rolesList = $this->accountUsersListArchived();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(4, $responseData['total']);
    }

    public function testAccountDeleteFlow(): void {
        $responseListWithCreated = $this->accountUsersList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(5, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountUsersListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountUserDelete($this->account_user_to_delete_id);
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountUsersList($this->main_account_slug);
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(4, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountUsersListArchived($this->main_account_slug);
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(1, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountUserForceDelete($this->account_user_to_delete_id);
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountUsersList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(4, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountUsersListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);
    }

    protected function accountUserCreate(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUsersList(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUsersListArchived(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserShow(string $user_id): TestResponse {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserUpdate(string $user_id, array $data): TestResponse {
        return $this->json(
            method: 'patch',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users/$user_id",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserAddEmails(string $user_id, array $data): TestResponse {
        return $this->json(
            method: 'patch',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users/$user_id/emails",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserRemoveEmails(string $user_id, array $data): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users/$user_id/emails",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserDelete(string $user_id): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserRestore(string $user_id): TestResponse {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users/$user_id/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserForceDelete(string $user_id): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users/$user_id/force_delete",
            headers: $this->getHeaders(),
        );
    }
}
