<?php

namespace Tests\Api\default\Admin;

use Illuminate\Testing\TestResponse;
use Tests\Api\default\Accounts\Traits\AccountAuthFunctions;
use Tests\Api\default\Admin\Traits\AdminAuthFunctions;
use Tests\Api\default\Admin\Traits\AdminRoleFunctions;
use Tests\Helpers\TenantLabels;
use Tests\TestCase;

class ApiDefaultAdminMiddlewareTest extends TestCase
{
    use AdminRoleFunctions, AdminAuthFunctions, AccountAuthFunctions;

    protected TenantLabels $tenant;

    public function testLoginAllAdminUsers(): void {
        $response = $this->adminLogin($this->landlord->user_superadmin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->landlord->user_cross_tenant_admin);
        $response->assertStatus(200);

        $response = $this->adminLogin($this->landlord->user_cross_tenant_visitor);
        $response->assertStatus(200);
    }

    public function testLoginAccountUsers():void {
        $this->tenant = $this->landlord->tenant_primary;
        $response = $this->accountLogin($this->landlord->tenant_primary->account_primary->user_admin);
        $response->assertStatus(200);
    }

    public function testListNoPermission(): void {
        $rolesList = $this->list(
            $this->getHeadersLandlordUserWithoutAccess()
        );

        $rolesList->assertStatus(403);
    }

    public function testListWithAccountPermission(): void {
        $rolesList = $this->list(
            $this->getHeadersAccountUser()
        );

        $rolesList->assertStatus(401);
    }

    public function testListWithPermissionWithoutTenant(): void {
        $rolesList = $this->list(
            $this->getHeadersLandlordUserWithoutAccess()
        );
        $rolesList->assertStatus(403);
    }

    protected function getHeadersLandlordUserWithAccess(): array {

        $token = $this->landlord->user_superadmin->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }

    protected function getHeadersLandlordUserWithoutAccess(): array {

        $token = $this->landlord->user_cross_tenant_visitor->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }

    protected function getHeadersAccountUser(): array {

        $token = $this->landlord->tenant_primary->account_primary->user_admin->token;

        return [
            'Authorization' => "Bearer $token",
            'Content-Type' => 'application/json'
        ];
    }

    protected function create(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/roles",
            data: $data,
            headers: $this->getHeadersLandlordUserWithAccess(),
        );
    }

    protected function list(array $headers): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/roles",
            headers: $headers,
        );
    }

}
