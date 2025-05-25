<?php

namespace Tests\Api\default;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultLandlordTenantTest extends TestCaseAuthenticated {

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
        $company_name = fake()->company();
        $this->tenant_2_slug = Str::slug($company_name);

        $response = $this->tenantsCreate([
            "name" => $company_name,
            "subdomain" => $this->tenant_2_slug,
            "domains" => [
                $this->tenant_2_slug,
            ],
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
            "domains" => [
                $this->tenant_2_slug,
            ]
        ]);

        $response->assertStatus(422);
        $this->assertEquals("The subdomain has already been taken", $response->json()['message']);;
    }

    public function testTenantsShow(): void {
        $tenantsShow = $this->tenantsShow();
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

    public function testTenantsSoftDelete(): void {

    }

    public function testTenantsListArchived(): void {}

    public function testTenantsRestore(): void {}

    public function testTenantsUpdate(): void {}

    public function testTenantsDeleteFlow(): void {}

    public function testTenantsUsersAttach(): void {}

    public function testTenantsUsersDetach(): void {}

    public function testTenantsUsersList(): void {}

    protected function tenantsList(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/tenants",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsShow(): TestResponse {
        return $this->json(
            method: 'get',
            uri: "admin/api/tenants/$this->tenant_1_slug",
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
}
