<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountRolesTest extends TestCaseAuthenticated
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

    protected string $secondary_account_slug {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SECONDARY_ACCOUNT_SLUG->value);
        }
    }

    protected string $secondary_role_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_ROLE_ID->value, $value);
            $this->secondary_role_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ROLE_ID->value);
        }
    }

    protected string $tenant_2_main_account_role_admin_id {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_ADMIN_ID->value);
        }
    }

    protected string $tenant_2_main_account_role_usermanage_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_USERMANAGE_ID->value, $value);
            $this->tenant_2_main_account_role_usermanage_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_USERMANAGE_ID->value);
        }
    }

    protected string $tenant_2_main_account_role_rolemanage_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_ROLEMANAGE_ID->value, $value);
            $this->tenant_2_main_account_role_rolemanage_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_ROLEMANAGE_ID->value);
        }
    }

    protected string $tenant_2_main_account_role_visitor_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_VISITOR_ID->value, $value);
            $this->tenant_2_main_account_role_visitor_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_VISITOR_ID->value);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->account_slug = 'test-account';
    }

    public function testAccountRolesList(): void
    {
        $rolesList = $this->accountRolesList($this->main_account_slug);
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(0, $responseData['total']);
        $this->assertArrayHasKey('data', $responseData);
    }

    public function testAccountRolesCreate(): void
    {

        $response = $this->accountRolesCreate(
            $this->main_account_slug,
            [
                "name" => "Account Editor Role",
                "description" => "Role for account editing",
                "permissions" => ["account-users:view", "account-users:create"],
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            "data" => [
                "name",
                "description",
                "permissions",
                "account_id",
                "created_at",
            ]
        ]);

        $this->secondary_role_id = $response->json()['data']['id'];
    }

    public function testAccountRolesShow(): void
    {
        $rolesShow = $this->accountRolesShow($this->main_account_slug, $this->secondary_role_id);
        $rolesShow->assertOk();
        $rolesShow->assertJsonStructure([
            "data" => [
                "name",
                "description",
                "permissions",
                "account_id",
                "created_at",
            ],
        ]);
    }

    public function testAccountRolesUpdate(): void
    {
        $roleUpdate = $this->accountRolesUpdate(
            $this->main_account_slug,
            $this->secondary_role_id,
            [
                "name" => "Updated Account Role",
                "permissions" => ["account-users:view", "account-users:create", "account-users:update"],
            ]
        );

        $roleUpdate->assertStatus(200);

        $rolesShow = $this->accountRolesShow($this->main_account_slug, $this->secondary_role_id);
        $rolesShow->assertOk();

        $this->assertEquals("Updated Account Role", $rolesShow->json()['data']['name']);
        $this->assertEquals(
            ["account-users:view", "account-users:create", "account-users:update"],
            $rolesShow->json()['data']['permissions']
        );
    }

    public function testAccountRolesDelete(): void
    {

        $rolesList = $this->accountRolesList($this->main_account_slug);
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(2, $responseData['total']);

        $deleteResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $this->secondary_role_id,
            [
                "role_id" => $this->tenant_2_main_account_role_admin_id
            ]
        );
        $deleteResponse->assertStatus(200);

        $rolesList = $this->accountRolesList($this->main_account_slug);
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(1, $responseData['total']);

        $rolesList = $this->accountRolesListArchived($this->main_account_slug);
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(1, $responseData['total']);

        $showDeleted = $this->accountRolesShow($this->main_account_slug, $this->secondary_role_id);
        $showDeleted->assertStatus(404);
    }

    public function testAccountRolesRestore(): void
    {
        $restoreResponse = $this->accountRolesRestore($this->main_account_slug, $this->secondary_role_id);
        $restoreResponse->assertStatus(200);

        // Should be able to get the restored role
        $showResponse = $this->accountRolesShow($this->main_account_slug, $this->secondary_role_id);
        $showResponse->assertOk();

        $rolesList = $this->accountRolesListArchived($this->main_account_slug);
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(2, $responseData['total']);
    }

    public function testAccountRolesDeleteFlow(): void
    {
        $responseListWithCreated = $this->accountRolesList($this->main_account_slug);
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(2, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountRolesListArchived($this->main_account_slug);
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $this->secondary_role_id,
            [
                "role_id" => $this->tenant_2_main_account_role_admin_id
            ]
        );
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountRolesList($this->main_account_slug);
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountRolesListArchived($this->main_account_slug);
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(1, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountRolesForceDelete(
            $this->main_account_slug,
            $this->secondary_role_id,
        );
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountRolesList($this->main_account_slug);
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountRolesListArchived($this->main_account_slug);
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);

    }

    public function testAccountRolesCreateFinalRoles(): void {

        $response = $this->accountRolesCreate(
            $this->main_account_slug,
            [
                "name" => "User Manager",
                "description" => "Role for users management",
                "permissions" => ["account-users:*", 'account-roles:view'],
            ]
        );
        $response->assertStatus(201);
        $this->tenant_2_main_account_role_usermanage_id = $response->json()['data']['id'];

        $response = $this->accountRolesCreate(
            $this->main_account_slug,
            [
                "name" => "Role Manager",
                "description" => "Role for roles management",
                "permissions" => ["account-roles:*", "account-users:view"],
            ]
        );
        $response->assertStatus(201);
        $this->tenant_2_main_account_role_rolemanage_id = $response->json()['data']['id'];

        $response = $this->accountRolesCreate(
            $this->main_account_slug,
            [
                "name" => "Visitor",
                "description" => "Role for roles management",
                "permissions" => ["account-content:view"],
            ]
        );
        $response->assertStatus(201);
        $this->tenant_2_main_account_role_visitor_id = $response->json()['data']['id'];

        $rolesList = $this->accountRolesList($this->main_account_slug);
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(4, $responseData['total']);
    }

    protected function accountRolesList(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesListArchived(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesShow(string $account_slug, string $roleId): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesCreate(string $account_slug, array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesUpdate(string $account_slug, string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesDelete(string $account_slug, string $roleId, array $data): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesForceDelete(string $account_slug, string $roleId): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesRestore(string $account_slug, string $roleId): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId/restore",
            headers: $this->getHeaders(),
        );
    }
}
