<?php

namespace Tests\Api\v1\Admin;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCaseAuthenticated;

class ApiV1AdminTenantTest extends TestCaseAuthenticated
{
    public function test_tenants_list(): void
    {
        $tenantsList = $this->tenantsList();
        $tenantsList->assertOk();

        $responseData = $tenantsList->json();
        $this->assertEquals(1, $responseData['total']);
        $this->assertCount(1, $responseData['data']);
        $this->assertEquals(1, $responseData['current_page']);
        $this->assertArrayHasKey('main_domain', $responseData['data'][0]);
        $this->assertNotEmpty($responseData['data'][0]['main_domain']);
        $this->assertArrayHasKey('domains', $responseData['data'][0]);
        $this->assertIsArray($responseData['data'][0]['domains']);
        $this->assertNotEmpty($responseData['data'][0]['domains']);
    }

    public function test_tenants_create(): void
    {

        $response = $this->tenantsCreate([
            'name' => $this->landlord->tenant_secondary->name,
            'subdomain' => $this->landlord->tenant_secondary->subdomain,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'name',
                'subdomain',
                'slug',
                'database',
                'created_at',
            ],
        ]);

        $this->landlord->tenant_secondary->slug = $response->json()['data']['slug'];
        $this->landlord->tenant_secondary->id = $response->json()['data']['id'];

        $this->landlord->tenant_secondary->role_admin->name = 'Admin';
        $this->landlord->tenant_secondary->role_admin->id = $response->json()['data']['role_admin_id'];

        $tenantsList = $this->tenantsList();
        $tenantsList->assertOk();

        $this->assertEquals(2, $tenantsList->json()['total']);
    }

    public function test_tenants_create_disposable(): void
    {

        $response = $this->tenantsCreate([
            'name' => $this->landlord->tenant_disposable->name,
            'subdomain' => $this->landlord->tenant_disposable->subdomain,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'name',
                'subdomain',
                'slug',
                'database',
                'created_at',
            ],
        ]);

        $this->landlord->tenant_disposable->slug = $response->json()['data']['slug'];
        $this->landlord->tenant_disposable->id = $response->json()['data']['id'];
        $this->landlord->tenant_disposable->role_admin->id = $response->json()['data']['role_admin_id'];

        $tenantsList = $this->tenantsList();
        $tenantsList->assertOk();

        $this->assertEquals(3, $tenantsList->json()['total']);
    }

    public function test_tenants_create_existent_subdomain(): void
    {

        $response = $this->tenantsCreate([
            'name' => 'tenant-subdomain-conflict-'.Str::uuid()->toString(),
            'subdomain' => $this->landlord->tenant_disposable->subdomain,
        ]);

        $response->assertStatus(422);
        $this->assertEquals('The subdomain has already been taken', $response->json()['message']);
    }

    public function test_tenants_create_existent_subdomain_uses_landlord_connection_even_with_tenant_default(): void
    {
        $originalDefaultConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('tenant');

        try {
            $response = $this->tenantsCreate([
                'name' => 'tenant-subdomain-conflict-'.Str::uuid()->toString(),
                'subdomain' => $this->landlord->tenant_disposable->subdomain,
            ]);
        } finally {
            DB::setDefaultConnection($originalDefaultConnection);
        }

        $response->assertStatus(422);
        $this->assertEquals('The subdomain has already been taken', $response->json()['message']);
    }

    public function test_tenants_show(): void
    {
        $tenantsShow = $this->tenantsShow($this->landlord->tenant_disposable->slug);
        $tenantsShow->assertOk();
        $tenantsShow->assertJsonStructure([
            'data' => [
                'name',
                'subdomain',
                'slug',
                'database',
                'created_at',
            ],
        ]);

        $this->assertEquals($this->landlord->tenant_disposable->slug, $tenantsShow->json()['data']['slug']);
    }

    public function test_tenants_soft_delete(): void
    {
        $deleteResponse = $this->tenantsDelete($this->landlord->tenant_disposable->slug);
        $deleteResponse->assertStatus(200);

        $listResponse = $this->tenantsList();
        $listResponse->assertOk();
        $this->assertEquals(2, $listResponse->json('total'));
    }

    public function test_tenants_list_archived(): void
    {
        $archivedResponse = $this->tenantsListArchived();
        $archivedResponse->assertOk();
        $data = $archivedResponse->json();

        $this->assertGreaterThanOrEqual(1, $data['total'] ?? 0);
        $this->assertNotEmpty($data['data'] ?? []);
        $this->assertEquals($this->landlord->tenant_disposable->slug, $data['data'][0]['slug']);
    }

    public function test_tenants_restore(): void
    {
        $restoreResponse = $this->tenantsRestore($this->landlord->tenant_disposable->slug);
        $restoreResponse->assertStatus(200);

        $listResponse = $this->tenantsList();
        $this->assertEquals(3, $listResponse->json('total') ?? 0);
    }

    public function test_tenants_update(): void
    {
        $tenantUpdate = $this->tenantsUpdate(
            $this->landlord->tenant_disposable->slug,
            [
                'name' => 'Updated Tenant',
            ]
        );

        $tenantUpdate->assertStatus(200);

        $new_slug = Str::slug('Updated Tenant');

        $tenantsShow = $this->tenantsShow($new_slug);
        $tenantsShow->assertOk();

        $this->assertEquals('Updated Tenant', $tenantsShow->json()['data']['name']);

        $this->landlord->tenant_disposable->slug = $tenantsShow->json()['data']['slug'];
    }

    public function test_tenants_delete_flow(): void
    {

        $response = $this->tenantsList();
        $this->assertEquals(3, count($response['data']));

        $response = $this->tenantsDelete($this->landlord->tenant_disposable->slug);
        $response->assertStatus(200);

        $response = $this->tenantsList();
        $this->assertEquals(2, count($response['data']));

        $response = $this->tenantsListArchived();
        $this->assertEquals(1, count($response['data']));

        $response = $this->tenantsForceDelete($this->landlord->tenant_disposable->slug);
        $response->assertStatus(200);

        $response = $this->tenantsList();
        $this->assertEquals(2, count($response['data']));

        $response = $this->tenantsListArchived();
        $this->assertEquals(0, count($response['data']));
    }

    protected function tenantsList(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: 'admin/api/v1/tenants',
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsShow(string $slug): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: "admin/api/v1/tenants/$slug",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsCreate(array $data): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: 'admin/api/v1/tenants',
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsUpdate(string $slug, array $data): TestResponse
    {
        return $this->json(
            method: 'patch',
            uri: "admin/api/v1/tenants/$slug",
            data: $data,
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsDelete(string $tenant_slug): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "admin/api/v1/tenants/$tenant_slug",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsForceDelete(string $tenant_slug): TestResponse
    {
        return $this->json(
            method: 'delete',
            uri: "admin/api/v1/tenants/$tenant_slug/force_delete",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsRestore(string $tenant_slug): TestResponse
    {
        return $this->json(
            method: 'post',
            uri: "admin/api/v1/tenants/$tenant_slug/restore",
            headers: $this->getHeaders(),
        );
    }

    protected function tenantsListArchived(): TestResponse
    {
        return $this->json(
            method: 'get',
            uri: 'admin/api/v1/tenants?archived=true',
            headers: $this->getHeaders(),
        );
    }
}
