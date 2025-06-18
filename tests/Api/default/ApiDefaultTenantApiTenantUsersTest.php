<?php

namespace Tests\Api\default;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Enums\TestVariableLabels;
use Tests\TestCase;
use Tests\TestCaseAuthenticated;

class ApiDefaultTenantApiTenantUsersTest extends TestCaseAuthenticated {

    protected string $secondary_landlord_user_id {
        get {
            return $this->getGlobal(TestVariableLabels::SECONDARY_LANDLORD_USER_ID->value);
        }
    }

    protected string $tenant_2_role_template_admin_id {
        get{
            return $this->getGlobal(TestVariableLabels::TENANT_2_ROLE_TEMPLATE_ADMIN_ID->value);
        }
    }

    protected string $tenant_2_id {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_ID->value);
        }
    }

    protected string $tenant_2_subdomain {
        get {
            return $this->getGlobal(TestVariableLabels::TENANT_2_SUBDOMAIN->value);
        }
    }

    public function testUserAttachTenant(): void {

        $response = $this->tenantUserAttach([
            "user_id" => $this->secondary_landlord_user_id,
            "role_id" => $this->tenant_2_role_template_admin_id,
        ]);
        $response->assertStatus(200);

        $responseShow = $this->tenantUserShow($this->secondary_landlord_user_id);

        $responseShow->assertStatus(200);
        $responseShow->assertJsonStructure([
            "data" => [
                "tenant_roles" => [
                    "*" => [
                        "slug",
                        "tenant_id",
                    ]
                ]
            ]
        ]);

        $this->assertEquals("admin", $responseShow->json()['data']['tenant_roles'][0]['slug']);
        $this->assertEquals($this->tenant_2_id, $responseShow->json()['data']['tenant_roles'][0]['tenant_id']);
    }

    public function testUserDettachAccount(): void {
        $response = $this->tenantUserDettach([
            "user_id" => $this->secondary_landlord_user_id,
            "role_id" => $this->tenant_2_role_template_admin_id,
        ]);
        $response->assertStatus(200);


        $responseShow = $this->tenantUserShow($this->secondary_landlord_user_id);

        $responseShow->assertStatus(200);
        $responseShow->assertJsonStructure([
            "data" => [
                "tenant_roles"
            ]
        ]);

        $this->assertEquals(0, count($responseShow->json()['data']['tenant_roles']));
    }

    protected function tenantUserShow(string $user_id): TestResponse {
        return $this->json(
            method: 'get',
            uri: "http://$this->tenant_2_subdomain.localhost/api/tenant-users/$user_id",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUserAttach(array $data): TestResponse {
        return $this->json(
            method: 'post',
            uri: "http://$this->tenant_2_subdomain.localhost/api/tenant-users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantUserDettach(array $data): TestResponse {
        return $this->json(
            method: 'delete',
            uri: "http://$this->tenant_2_subdomain.localhost/api/tenant-users",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

}
