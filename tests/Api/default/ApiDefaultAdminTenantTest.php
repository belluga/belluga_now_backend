<?php

namespace Tests\Api\default;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultAdminTenantTest extends TestCaseAuthenticated {

    protected string $tenant_1_slug {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_1_SLUG->value);
        }
    }

    protected string $tenant_2_slug {
        set(string $value) {
            $this->setGlobal(TestVariableLabels::TENANT_2_SLUG->value, $value);
            $this->tenant_2_slug = $value;;
        }
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SLUG->value);
        }
    }

    protected ?string $secondary_landlord_user_id {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_ID->value);
        }
    }

    public function testTenantsList(): void {
        $tenantsList = $this->tenantsList();
        $tenantsList->assertOk();

        $responseData = $tenantsList->json();
        $this->assertEquals(1, $responseData['total']);
        $this->assertCount(1, $responseData['data']);
        $this->assertEquals(1, $responseData['last_page']);
        $this->assertEquals(1, $responseData['current_page']);
        $this->assertEquals(15, $responseData['per_page']);
    }

    public function testTenantsCreate(): void {
        $company_name = "Temporary Test";
        $this->tenant_2_slug = Str::slug($company_name);

        $response = $this->tenantsCreate([
            "name" => $company_name,
            "subdomain" => $this->tenant_2_slug,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            "data" => [
                "name",
                "subdomain",
                "slug",
                "database",
                "created_at",
            ]
        ]);

        $this->tenant_2_slug = $response->json()['data']['slug'];

        $tenantsList = $this->tenantsList();
        $tenantsList->assertOk();
        $this->assertEquals(2, $tenantsList->json()['total']);
    }

    public function testTenantsCreateExistentSubdomain(): void {
        $company_name = fake()->company();
        $response = $this->tenantsCreate([
            "name" => $company_name,
            "subdomain" => $this->tenant_2_slug,
        ]);

        $response->assertStatus(422);
        $this->assertEquals("The subdomain has already been taken", $response->json()['message']);;
    }

    public function testTenantsShow(): void {
        $tenantsShow = $this->tenantsShow($this->tenant_1_slug);
        $tenantsShow->assertOk();;
        $tenantsShow->assertJsonStructure([
            "data" => [
                "name",
                "subdomain",
                "slug",
                "database",
                "created_at",
            ],
        ]);
    }

    public function testTenantsSoftDelete(): void
    {
        $deleteResponse = $this->tenantsDelete($this->tenant_2_slug);
        $deleteResponse->assertStatus(200);

        $listResponse = $this->tenantsList();
        $listResponse->assertOk();
        $this->assertEquals(1, $listResponse->json('total') ?? 0);
    }

    public function testTenantsListArchived(): void
    {
        $archivedResponse = $this->tenantsListArchived();
        $archivedResponse->assertOk();
        $data = $archivedResponse->json();

        $this->assertGreaterThanOrEqual(1, $data['total'] ?? 0);
        $this->assertNotEmpty($data['data'] ?? []);
        $this->assertEquals($this->tenant_2_slug, $data['data'][0]['slug']);
    }

    public function testTenantsRestore(): void
    {
        $restoreResponse = $this->tenantsRestore($this->tenant_2_slug);
        $restoreResponse->assertStatus(200);

        $listResponse = $this->tenantsList();
        $this->assertEquals(2, $listResponse->json('total') ?? 0);
    }

    public function testTenantsUpdate(): void {
        $tenantUpdate = $this->tenantsUpdate(
            $this->tenant_2_slug,
            [
                "name" => "Updated Tenant",
            ]
        );

        $tenantUpdate->assertStatus(200);

        $new_slug = Str::slug("Updated Tenant");

        $tenantsShow = $this->tenantsShow($new_slug);
        $tenantsShow->assertOk();

        $this->assertEquals("Updated Tenant", $tenantsShow->json()['data']['name']);
    }

    public function testTenantsDeleteFlow(): void {

        $company = "To Be Deleted Company";
        $tenant_slug = Str::slug($company);

        $response = $this->tenantsCreate([
            "name" => $company,
            "subdomain" => $tenant_slug,
        ]);
        $response->assertStatus(201);

        $response = $this->tenantsList();
        $this->assertEquals(3, count($response['data']));

        $response = $this->tenantsDelete($tenant_slug);
        $response->assertStatus(200);

        $response = $this->tenantsList();
        $this->assertEquals(2, count($response['data']));

        $response = $this->tenantsListArchived();
        $this->assertEquals(1, count($response['data']));

        $response = $this->tenantsForceDelete($tenant_slug);
        $response->assertStatus(200);

        $response = $this->tenantsList();
        $this->assertEquals(2, count($response['data']));

        $response = $this->tenantsListArchived();
        $this->assertEquals(0, count($response['data']));
    }

    protected function tenantsList(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/tenants",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsShow(string $slug): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/tenants/$slug",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsCreate(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/tenants",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsUpdate(string $slug ,array $data): TestResponse {
        return $this->json(
            method: 'patch',
            uri: "admin/api/tenants/$slug",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsDelete(string $tenant_slug): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "admin/api/tenants/$tenant_slug",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsForceDelete(string $tenant_slug): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "admin/api/tenants/$tenant_slug/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsRestore(string $tenant_slug): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/tenants/$tenant_slug/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsListArchived(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/tenants?archived=true",
            headers: $this->getHeaders(),
        );
    }
}
