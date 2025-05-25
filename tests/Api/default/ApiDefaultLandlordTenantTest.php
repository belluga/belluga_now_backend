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

    public function testTenantsCreate(): void {}

    public function testTenantsCreateExistentDomain(): void {}

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

    public function testTenantsSoftDelete(): void {}

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
}
