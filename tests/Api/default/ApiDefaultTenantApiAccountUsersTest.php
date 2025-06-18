<?php

namespace Tests\Api\default;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultTenantApiAccountUsersTest extends TestCaseAuthenticated {

    protected string $secondary_account_user_admin_id {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_USER_ADMIN_ID->value);
        }
    }

    protected string $tenant_2_main_account_slug {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_MAIN_ACCOUNT_SLUG->value);
        }
    }

    protected string $tenant_2_account_role_visitor_id {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_VISITOR_ID->value);
        }
    }

    protected string $tenant_2_id {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ID->value);
        }
    }

    protected string $tenant_2_subdomain {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SUBDOMAIN->value);
        }
    }

    protected string $tenant_2_role_template_admin_id {
        get{
            return $this->getGlobal(TestVariableLabels::TENANT_2_ROLE_TEMPLATE_ADMIN_ID->value);
        }
    }

    public function testUserShow(): void {
        $responseShow = $this->tenantUserShow($this->secondary_account_user_admin_id);

        $responseShow->assertStatus(200);
        $responseShow->assertJsonStructure([
            "data" => [
                "name",
                "emails",
                "account_roles" => [
                    "*" => [
                        "slug",
                        "account_id",
                    ]
                ]
            ]
        ]);
    }

    public function testUsersList(): void {
        $response = $this->tenantUsersList();
        $response->assertStatus(200);

        $response->assertJsonStructure([
            "total",
            "data" => [
                "*" => [
                    "id",
                    "name",
                    "emails",
                    "account_roles"
                ]
            ]
        ]);

        $this->assertEquals(5, $response->json()['total']);
    }

    public function testUserDelete(): void {

        $response = $this->accountUserDelete($this->secondary_account_user_admin_id);
        $response->assertStatus(200);

        $responseList = $this->tenantUsersList();
        $responseList->assertStatus(200);
        $this->assertEquals(4, $responseList->json()['total']);

        $responseArchived = $this->tenantUsersListArchived();
        $responseArchived->assertStatus(200);
        $this->assertEquals(2, $responseArchived->json()['total']);
    }

    public function testUserRestore(): void {
        $response = $this->accountUserRestore($this->secondary_account_user_admin_id);
        $response->assertStatus(200);

        $responseList = $this->tenantUsersList();
        $responseList->assertStatus(200);
        $this->assertEquals(5, $responseList->json()['total']);

        $responseArchived = $this->tenantUsersListArchived();
        $responseArchived->assertStatus(200);
        $this->assertEquals(1, $responseArchived->json()['total']);
    }

    protected function tenantUsersList(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "http://$this->tenant_2_subdomain.localhost/api/account-users",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUsersListArchived(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "http://$this->tenant_2_subdomain.localhost/api/account-users?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUserShow(string $user_id): TestResponse {
        return $this->json(
            method: 'get',
            uri: "http://$this->tenant_2_subdomain.localhost/api/account-users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserDelete(string $user_id): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "http://$this->tenant_2_subdomain.localhost/api/account-users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserRestore(string $user_id): TestResponse {
        return $this->json(
            method: 'post',
            uri: "http://$this->tenant_2_subdomain.localhost/api/account-users/$user_id/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function accountUserForceDelete(string $user_id): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "http://$this->tenant_2_subdomain.localhost/api/account-users/$user_id/force_delete",
            headers: $this->getHeaders(),
        );
    }

}
