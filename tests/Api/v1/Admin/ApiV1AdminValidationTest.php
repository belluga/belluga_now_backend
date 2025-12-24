<?php

namespace Tests\Api\v1\Admin;

use Illuminate\Testing\TestResponse;
use Tests\TestCaseAuthenticated;

class ApiV1AdminValidationTest extends TestCaseAuthenticated {

    public function testInitialization(): void {
        $response = $this->initiate();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "errors" => [
                "tenant.name",
                "tenant.subdomain",
                "user.email",
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
            uri: "api/v1/initialize"
        );
    }

    protected function tenantsCreate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/v1/tenants",
            headers: $this->getHeaders()
        );
    }

}
