<?php

namespace Tests\Api\default\Admin;

use Illuminate\Testing\TestResponse;
use Tests\TestCaseAuthenticated;

class ApiDefaultAdminValidationTest extends TestCaseAuthenticated {

    public function testInitialization(): void {
        $response = $this->initiate();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "errors" => [
                "tenant.name",
                "tenant.subdomain",
                "tenant.domains",
                "user.emails",
                "user.password",
            ]
        ]);
    }

    public function testTenantCreation(): void {
        $response = $this->tenantsCreate();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "errors" => [
                "name",
                "subdomain",
            ]
        ]);
    }

    protected function initiate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "initialize"
        );
    }

    protected function tenantsCreate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/tenants",
            headers: $this->getHeaders()
        );
    }

}
