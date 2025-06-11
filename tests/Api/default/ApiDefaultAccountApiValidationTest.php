<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountApiValidationTest extends TestCaseAuthenticated
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

    protected string $main_role_id {
        get {
            return $this->getGlobal(TestVariableLabels::ACCOUNT_ROLE_ADMIN_ID->value);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->account_slug = 'test-account';
    }

    public function testAccountRolesCreate(): void
    {

        $response = $this->accountRolesCreate($this->main_account_slug);

        $response->assertStatus(422);
        $response->assertJsonStructure([
            "message",
            "errors" => [
                "name",
                "permissions"
            ]
        ]);
    }

    public function testAccountRolesUpdate(): void
    {

        $response = $this->accountRolesUpdate(
            $this->main_account_slug,
            $this->main_role_id
        );

        $response->assertStatus(422);
        $response->assertJsonStructure([
            "message",
            "errors" => [
                "empty",
            ]
        ]);
    }

    public function testAccountRolesDelete(): void
    {


        $deleteResponse = $this->accountRolesDelete(
            $this->main_account_slug,
            $this->main_role_id,
            []
        );
        $deleteResponse->assertStatus(422);

        $deleteResponse->assertJsonStructure([
           "message",
           "errors" => [
               "role_id"
           ]
        ]);
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
                "role_id"
            ],
        ]);
    }

    protected function accountRolesCreate(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRolesUpdate(string $account_slug, string $roleId): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$account_slug/roles/$roleId",
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

    protected function userCreate(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_subdomain}.localhost/api/accounts/$this->main_account_slug/users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }
}
