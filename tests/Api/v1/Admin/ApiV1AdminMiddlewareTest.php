<?php

namespace Tests\Api\v1\Admin;

use Illuminate\Testing\TestResponse;
use Tests\Api\Traits\AccountAuthFunctions;
use Tests\Api\Traits\AdminAuthFunctions;
use Tests\Api\Traits\AdminRoleFunctions;
use Tests\TestCase;

class ApiV1AdminMiddlewareTest extends TestCase
{
    use AccountAuthFunctions, AdminAuthFunctions, AdminRoleFunctions;

    public function test_login_all_admin_users(): void
    {
        $response = $this->adminLogin($this->landlord->user_superadmin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->landlord->user_cross_tenant_admin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->landlord->user_cross_tenant_visitor);
        $response->assertStatus(200);
    }

    public function test_login_account_users(): void
    {
        $response = $this->accountLoginRaw($this->landlord->tenant_primary, $this->landlord->tenant_primary->account_primary->user_admin);
        $response->assertStatus(200);
    }

    public function test_list_no_permission(): void
    {
        $rolesList = $this->list(
            $this->getHeadersLandlordUserWithoutAccess()
        );

        $rolesList->assertStatus(403);
    }

    public function test_list_with_account_permission(): void
    {
        $rolesList = $this->list(
            $this->getHeadersAccountUser()
        );

        $rolesList->assertStatus(401);
    }

    public function test_list_with_permission_without_tenant(): void
    {
        $rolesList = $this->list(
            $this->getHeadersLandlordUserWithoutAccess()
        );
        $rolesList->assertStatus(403);
    }

    protected function getHeadersLandlordUserWithAccess(): array
    {

        $token = $this->landlord->user_superadmin->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json',
        ];
    }

    protected function getHeadersLandlordUserWithoutAccess(): array
    {

        $token = $this->landlord->user_cross_tenant_visitor->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json',
        ];
    }

    protected function getHeadersAccountUser(): array
    {

        $token = $this->landlord->tenant_primary->account_primary->user_admin->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json',
        ];
    }

    protected function create(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: 'admin/api/v1/roles',
            data: $data,
            headers: $this->getHeadersLandlordUserWithAccess(),
        );
    }

    protected function list(array $headers): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: 'admin/api/v1/roles',
            headers: $headers,
        );
    }
}
