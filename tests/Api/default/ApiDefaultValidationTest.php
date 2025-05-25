<?php

namespace Tests\Api\default;

use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultValidationTest extends TestCaseAuthenticated {

    protected string $tenant_1_slug {
        get {
            $current_value = $this->getGlobal(TestVariableLabels::TENANT_1_SLUG->value);
            if ($current_value === null) {
                $this->setGlobal(TestVariableLabels::TENANT_1_SLUG->value, fake()->company());
            }
            return $this->getGlobal(TestVariableLabels::TENANT_1_SLUG->value);
        }
    }

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

    public function testTenantUserAttachWrongUserId(): void {
        $response = $this->userAttach(
            $this->tenant_1_slug,
            [
                'user_ids' => [
                    "123"
            ]
        ]);

        $response->assertStatus(422);

        $this->assertEquals("No users found", $response->json()['message']);
    }

    public function testTenantUserAttachWrongTenantSlug(): void {
        $response = $this->userAttach(
            "slug_inexistente",
            [
                'user_ids' => [
                    "123"
                ]
            ]);

        $response->assertStatus(422);

        $this->assertEquals("Tenant not found", $response->json()['message']);
    }

    public function testTenantUserAttach(): void {
        $response = $this->userAttach(
            $this->tenant_1_slug,
            []
        );

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "errors" => [
                "user_ids",
            ]
        ]);
    }

    public function testTenantUserDetach(): void {
        $response = $this->userAttach(
            $this->tenant_1_slug,
            []
        );

        $response->assertStatus(422);

        $response->assertJsonStructure([
            "errors" => [
                "user_ids",
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

    protected function tenantsCreate(): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/tenants",
            headers: $this->getHeaders()
        );
    }

    protected function userAttach(string $tenant_slug,array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "admin/api/tenants/$tenant_slug/users",
            data: $data,
            headers: $this->getHeaders()
        );
    }

}
