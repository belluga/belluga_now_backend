<?php

namespace Tests\Api\default\Tenants\Account\Contracts;

use Illuminate\Testing\TestResponse;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;

class ApiDefaultTenantAccountsTestContract extends TestCaseTenant
{

    protected TenantLabels $tenant {
        get{
            return $this->landlord->tenant_primary;
        }
    }

    protected string $base_api_url {
        get{
            return "{$this->base_api_tenant}accounts/";
        }
    }

    public function testAccountCreatePrimary(): void
    {
        $this->tenant->account_primary->name = fake()->company();
        $this->tenant->account_primary->document = fake()->cpf(false);

        $response = $this->accountCreate(
            [
                "name" => $this->tenant->account_primary->name,
                "document" => [
                    "type" => "cpf",
                    "number" => $this->tenant->account_primary->document
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

        $this->tenant->account_primary->id = $response->json()['data']['account']['id'];
        $this->tenant->account_primary->slug = $response->json()['data']['account']['slug'];
        $this->tenant->account_primary->role_admin->id = $response->json()['data']['role']['id'];
    }

    public function testAccountCreateSecondary(): void
    {
        $this->tenant->account_secondary->name = fake()->company();
        $this->tenant->account_secondary->document = fake()->cpf(false);

        $response = $this->accountCreate(
            [
                "name" => $this->landlord->tenant_primary->account_secondary->name,
                "document" => [
                    "type" => "cpf",
                    "number" => $this->landlord->tenant_primary->account_secondary->document
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

        $this->tenant->account_secondary->id = $response->json()['data']['account']['id'];
        $this->tenant->account_secondary->slug = $response->json()['data']['account']['slug'];
        $this->tenant->account_secondary->role_admin->id = $response->json()['data']['role']['id'];
    }

    public function testAccountCreateDisposableOnTenantPrimaryCreate(): void
    {
        $this->tenant->account_disposable->name = fake()->company();
        $this->tenant->account_disposable->document = fake()->cpf(false);

        $response = $this->accountCreate(
            [
                "name" => $this->landlord->tenant_primary->account_disposable->name,
                "document" => [
                    "type" => "cpf",
                    "number" => $this->landlord->tenant_primary->account_disposable->document
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

        $this->tenant->account_disposable->id = $response->json()['data']['account']['id'];
        $this->tenant->account_disposable->slug = $response->json()['data']['account']['slug'];
        $this->tenant->account_disposable->role_admin->id = $response->json()['data']['role']['id'];
    }

    public function testAccountShow(): void
    {
        $rolesShow = $this->accountShow(
            $this->tenant->account_primary->slug);

        $rolesShow->assertOk();
        $rolesShow->assertJsonStructure([
            "data" => [
                "name",
                "created_at",
            ],
        ]);

        $rolesShow = $this->accountShow(
            $this->tenant->account_secondary->slug);

        $rolesShow->assertOk();
        $rolesShow->assertJsonStructure([
            "data" => [
                "name",
                "created_at",
            ],
        ]);

        $rolesShow = $this->accountShow(
            $this->tenant->account_disposable->slug);

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
        $this->tenant->account_disposable->name = fake()->company() . " Updated";

        $roleUpdate = $this->accountUpdate(
            $this->tenant->account_disposable->slug,
            [
                "name" => $this->tenant->account_disposable->name,
                "document" => [
                    "type" => "cpf",
                    "number" => fake()->cpf(false),
                ]
            ]
        );

        $roleUpdate->assertStatus(200);

        $this->assertEquals(
            $this->tenant->account_disposable->name,
            $roleUpdate->json()['data']['name']);

        $this->assertEquals(
            "cpf",
            $roleUpdate->json()['data']['document']['type']
        );
        $this->tenant->account_disposable->slug = $roleUpdate->json()['data']['slug'];
    }

    public function testAccountDelete(): void
    {
        $responseList = $this->accountsList();
        $this->assertArrayHasKey('total', $responseList->json());
        $this->equalTo(2, $responseList->json()['total']);

        $deleteResponse = $this->accountDelete(
            $this->tenant->account_disposable->slug);

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
        $showResponse = $this->accountShow(
            $this->tenant->account_disposable->slug);
        $showResponse->assertStatus(404);

        $responseListWithCreated = $this->accountsList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountsListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(1, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountRestore(
            $this->tenant->account_disposable->slug);
        $restoreResponse->assertStatus(200);

        $showResponse = $this->accountShow(
            $this->tenant->account_disposable->slug);
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

        $restoreResponse = $this->accountDelete(
            $this->tenant->account_disposable->slug);
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountsList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountsListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(1, $responseListArchived->json()['total']);

        $restoreResponse = $this->accountForceDelete(
            $this->tenant->account_disposable->slug);
        $restoreResponse->assertStatus(200);

        $responseListWithCreated = $this->accountsList();
        $this->assertArrayHasKey('total', $responseListWithCreated->json());
        $this->equalTo(1, $responseListWithCreated->json()['total']);

        $responseListArchived = $this->accountsListArchived();
        $this->assertArrayHasKey('total', $responseListArchived->json());
        $this->equalTo(0, $responseListArchived->json()['total']);
    }

    protected function accountsList(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: $this->base_api_url,
            headers: $this->getHeaders(),
        );
    }

    protected function accountsListArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "{$this->base_api_url}?archived=true",
            headers: $this->getHeaders(),
        );
    }

    protected function accountShow(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "{$this->base_api_url}$account_slug",
            headers: $this->getHeaders(),
        );
    }

    protected function accountCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: $this->base_api_url,
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountUpdate(string $account_slug, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "{$this->base_api_url}$account_slug",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function accountDelete(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "{$this->base_api_url}$account_slug",
            headers: $this->getHeaders(),
        );
    }

    protected function accountRestore(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}$account_slug/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function accountForceDelete(string $account_slug): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "{$this->base_api_url}$account_slug/force_delete",
            headers: $this->getHeaders(),
        );
    }
}
