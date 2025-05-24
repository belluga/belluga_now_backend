<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultValidationTest extends TestCaseAuthenticated {

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

    public function testUserCreation(): void {
        $response = $this->userCreate();

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "errors" => [
                "name",
                "emails",
                "password",
            ]
        ]);
    }

    protected function initiate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/initialize"
        );
    }

    protected function userCreate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/users",
            headers: $this->getHeaders()
        );
    }

}
