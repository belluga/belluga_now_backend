<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCaseAuthenticated;

class ApiDefaultAccountTest extends TestCaseAuthenticated
{
    protected string $tenant_1_subdomain {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_1_SUBDOMAIN->value);
        }
    }

    protected string $tenant_2_subdomain {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SUBDOMAIN->value);
        }
    }

    protected string $tenant_1_main_account_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_1_MAIN_ACCOUNT_ID->value, $value);
            $this->tenant_1_main_account_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_1_MAIN_ACCOUNT_ID->value);
        }
    }

    protected string $tenant_1_main_account_slug {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_1_MAIN_ACCOUNT_SLUG->value, $value);
            $this->tenant_1_main_account_slug = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_1_MAIN_ACCOUNT_SLUG->value);
        }
    }

    protected string $tenant_2_main_account_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_MAIN_ACCOUNT_ID->value, $value);
            $this->tenant_2_main_account_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_MAIN_ACCOUNT_ID->value);
        }
    }

    protected string $tenant_2_secondary_account_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_SECONDARY_ACCOUNT_ID->value, $value);
            $this->tenant_2_secondary_account_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SECONDARY_ACCOUNT_ID->value);
        }
    }

    protected string $tenant_2_main_account_role_admin_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_ADMIN_ID->value, $value);
            $this->tenant_2_main_account_role_admin_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ACCOUNT_ROLE_ADMIN_ID->value);
        }
    }

    protected string $tenant_1_main_account_role_admin_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_1_ACCOUNT_ROLE_ADMIN_ID->value, $value);
            $this->tenant_1_main_account_role_admin_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_1_ACCOUNT_ROLE_ADMIN_ID->value);
        }
    }

    protected string $secondary_account_role_admin_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::SECONDARY_ACCOUNT_ROLE_ADMIN_ID->value, $value);
            $this->secondary_account_role_admin_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_ACCOUNT_ROLE_ADMIN_ID->value);
        }
    }

    protected string $tenant_2_main_account_slug {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_MAIN_ACCOUNT_SLUG->value, $value);
            $this->tenant_2_main_account_slug = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_MAIN_ACCOUNT_SLUG->value);
        }
    }

    protected string $secondary_account_id {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_SECONDARY_ACCOUNT_ID->value, $value);
            $this->secondary_account_id = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SECONDARY_ACCOUNT_ID->value);
        }
    }

    protected string $secondary_account_slug {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_SECONDARY_ACCOUNT_SLUG->value, $value);
            $this->secondary_account_slug = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SECONDARY_ACCOUNT_SLUG->value);
        }
    }

    protected string $delete_account_slug {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_DELETE_ACCOUNT_SLUG->value, $value);
            $this->delete_account_slug = $value;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_DELETE_ACCOUNT_SLUG->value);
        }
    }

    public function testAccountList(): void
    {
        $rolesList = $this->accountsList();
        $rolesList->assertOk();

        $responseData = $rolesList->json();
        $this->assertArrayHasKey('total', $responseData);
        $this->equalTo(0, $responseData['total']);
        $this->assertArrayHasKey('data', $responseData);
    }

    public function testAccountCreate(): void
    {
        $response = $this->accountCreate(
            [
                "name" => fake()->company(),
                "document" => [
                    "type" => "cpf",
                    "number" => fake()->cnpj(false)
                ],
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            "data" => [
                "account" => [
                    "id",
                    "name",
                    "slug",
                    "created_at"
                ],
                "role" => [
                    "id",
                    "slug"
                ]
            ]
        ]);

        $this->tenant_2_main_account_id = $response->json()['data']['account']['id'];
        $this->tenant_2_main_account_slug = $response->json()['data']['account']['slug'];
        $this->tenant_2_main_account_role_admin_id = $response->json()['data']['role']['id'];
    }

    public function testAccountMainTenantCreate(): void
    {
        $response = $this->accountCreateTenant1(
            [
                "name" => fake()->company(),
                "document" => [
                    "type" => "cpf",
                    "number" => fake()->cnpj(false)
                ],
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            "data" => [
                "account" => [
                    "id",
                    "name",
                    "slug",
                    "created_at"
                ],
                "role" => [
                    "id",
                    "slug"
                ]
            ]
        ]);

        $this->tenant_1_main_account_id = $response->json()['data']['account']['id'];
        $this->tenant_1_main_account_slug = $response->json()['data']['account']['slug'];
        $this->tenant_1_main_account_role_admin_id = $response->json()['data']['role']['id'];
    }

    public function testAccountCreateSecondary(): void
    {
        $response = $this->accountCreate(
            [
                "name" => fake()->company(),
                "document" => [
                    "type" => "cpf",
                    "number" => fake()->cnpj(false)
                ],
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            "data" => [
                "account" => [
                    "id",
                    "name",
                    "slug",
                    "created_at"
                ],
                "role" => [
                    "id",
                    "slug"
                ]
            ]
        ]);

        $this->secondary_account_id = $response->json()['data']['account']['id'];
        $this->secondary_account_slug = $response->json()['data']['account']['slug'];
        $this->secondary_account_role_admin_id = $response->json()['data']['role']['id'];
    }

    public function testAccountShow(): void
    {
        $rolesShow = $this->accountShow($this->tenant_2_main_account_slug);
        $rolesShow->assertOk();
        $rolesShow->assertJsonStructure([
            "data" => [
                "name",
                "created_at",
            ],
        ]);
    }

    public function testAccountUpdate(): void
    {
        $new_company_name = fake()->company() . " Updated";

        $roleUpdate = $this->accountUpdate(
            $this->tenant_2_main_account_slug,
            [
                "name" => $new_company_name,
                "document" => [
                    "type" => "cpf",
                    "number" => fake()->cpf(false),
                ]
            ]
        );

        $roleUpdate->assertStatus(200);

        $this->assertEquals($new_company_name, $roleUpdate->json()['data']['name']);
        $this->assertEquals(
            "cpf",
            $roleUpdate->json()['data']['document']['type']
        );
        $this->tenant_2_main_account_slug = $roleUpdate->json()['data']['slug'];
    }

    public function testAccountDelete(): void
    {
        $responseCreate = $this->accountCreate(
            [
                "name" => fake()->company(),
                "document" => [
                    "type" => "cpf",
                    "number" => fake()->cnpj(false)
                ],
                "permissions" => ["user.view", "user.create"],
            ]
        );

        $responseCreate->assertStatus(201);
        $this->delete_account_slug = $responseCreate->json()['data']['account']['slug'];

        $responseList = $this->accountsList();
        $this->assertArrayHasKey('total', $responseList->json());
        $this->equalTo(2, $responseList->json()['total']);

        $deleteResponse = $this->accountDelete($this->delete_account_slug);
        $deleteResponse->assertStatus(200);

        $responseListWithCreated = $this->accountsList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountsListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(1, $responseListArchived->json()['total']);
    }

    public function testAccountRestore(): void
    {
        $showResponse = $this->accountShow($this->delete_account_slug);
        $showResponse->assertStatus(404);

        $responseListWithCreated = $this->accountsList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountsListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(1, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountRestore($this->delete_account_slug);
        $restoreResponse->assertStatus(200);

        $showResponse = $this->accountShow($this->delete_account_slug);
        $showResponse->assertOk();

        $responseListWithCreated = $this->accountsList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(2, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountsListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);
    }

    public function testAccountDeleteFlow(): void
    {
        $responseListWithCreated = $this->accountsList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(2, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountsListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountDelete($this->delete_account_slug);
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountsList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountsListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(1, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountForceDelete($this->delete_account_slug);
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountsList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountsListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);

        //TODO: Test if it deleted the Roles or if I still have orphan roles in database
    }

    protected function accountsList(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts",
            headers: $this->getHeaders(),
        );
    }

    protected function accountsListArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function accountShow(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug",
            headers: $this->getHeaders(),
        );
    }

    protected function accountCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountCreateTenant1(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_1_subdomain}.localhost/api/accounts",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUpdate(string $account_slug, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountDelete(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRestore(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function accountForceDelete(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "http://{$this->tenant_2_subdomain}.localhost/api/accounts/$account_slug/force_delete",
            headers: $this->getHeaders(),
        );
    }
}
