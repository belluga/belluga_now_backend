<?php

namespace Tests\Api\v1\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Helpers\TenantLabels;
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
        $beforeTotal = $this->tenantsList()->json('total') ?? 0;

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

        $this->assertEquals($beforeTotal + 1, $tenantsList->json()['total']);
    }

    public function test_tenants_create_disposable(): void
    {
        $beforeTotal = $this->tenantsList()->json('total') ?? 0;

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

        $this->assertEquals($beforeTotal + 1, $tenantsList->json()['total']);
    }

    public function test_tenants_create_existent_subdomain(): void
    {
        $this->ensureDisposableTenantExists();

        $response = $this->tenantsCreate([
            'name' => 'tenant-subdomain-conflict-'.Str::uuid()->toString(),
            'subdomain' => $this->landlord->tenant_disposable->subdomain,
        ]);

        $response->assertStatus(422);
        $this->assertEquals('The subdomain has already been taken', $response->json()['message']);
    }

    public function test_tenants_create_existent_subdomain_uses_landlord_connection_even_with_tenant_default(): void
    {
        $this->ensureDisposableTenantExists();

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
        $this->ensureDisposableTenantExists();

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
        $this->ensureDisposableTenantExists();
        $beforeTotal = $this->tenantsList()->json('total') ?? 0;

        $deleteResponse = $this->tenantsDelete($this->landlord->tenant_disposable->slug);
        $deleteResponse->assertStatus(200);

        $listResponse = $this->tenantsList();
        $listResponse->assertOk();
        $this->assertEquals($beforeTotal - 1, $listResponse->json('total'));
    }

    public function test_tenants_list_archived(): void
    {
        $this->ensureDisposableTenantExists();
        $this->tenantsDelete($this->landlord->tenant_disposable->slug)->assertStatus(200);

        $archivedResponse = $this->tenantsListArchived();
        $archivedResponse->assertOk();
        $data = $archivedResponse->json();

        $this->assertGreaterThanOrEqual(1, $data['total'] ?? 0);
        $this->assertNotEmpty($data['data'] ?? []);
        $this->assertEquals($this->landlord->tenant_disposable->slug, $data['data'][0]['slug']);
    }

    public function test_tenants_restore(): void
    {
        $this->ensureDisposableTenantExists();
        $expectedTotalAfterRestore = $this->tenantsList()->json('total') ?? 0;
        $this->tenantsDelete($this->landlord->tenant_disposable->slug)->assertStatus(200);

        $restoreResponse = $this->tenantsRestore($this->landlord->tenant_disposable->slug);
        $restoreResponse->assertStatus(200);

        $listResponse = $this->tenantsList();
        $this->assertEquals($expectedTotalAfterRestore, $listResponse->json('total') ?? 0);
    }

    public function test_tenants_update(): void
    {
        $this->ensureDisposableTenantExists();

        $originalSlug = $this->landlord->tenant_disposable->slug;

        $tenantUpdate = $this->tenantsUpdate(
            $originalSlug,
            [
                'name' => 'Updated Tenant',
            ]
        );

        $tenantUpdate->assertStatus(200);
        $this->assertEquals($originalSlug, $tenantUpdate->json('data.slug'));

        $tenantsShow = $this->tenantsShow($originalSlug);
        $tenantsShow->assertOk();

        $this->assertEquals('Updated Tenant', $tenantsShow->json()['data']['name']);
        $this->assertEquals($originalSlug, $tenantsShow->json()['data']['slug']);
    }

    public function test_tenants_delete_flow(): void
    {
        $this->ensureSecondaryTenantExists();
        $this->ensureDisposableTenantExists();
        $startingActiveTotal = count($this->tenantsList()['data']);
        $startingArchivedTotal = count($this->tenantsListArchived()['data']);

        $response = $this->tenantsList();
        $this->assertEquals($startingActiveTotal, count($response['data']));

        $response = $this->tenantsDelete($this->landlord->tenant_disposable->slug);
        $response->assertStatus(200);

        $response = $this->tenantsList();
        $this->assertEquals($startingActiveTotal - 1, count($response['data']));

        $response = $this->tenantsListArchived();
        $this->assertEquals($startingArchivedTotal + 1, count($response['data']));

        $response = $this->tenantsForceDelete($this->landlord->tenant_disposable->slug);
        $response->assertStatus(200);

        $response = $this->tenantsList();
        $this->assertEquals($startingActiveTotal - 1, count($response['data']));

        $response = $this->tenantsListArchived();
        $this->assertEquals($startingArchivedTotal, count($response['data']));
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

    protected function ensureSecondaryTenantExists(): void
    {
        $this->ensureTenantExists($this->landlord->tenant_secondary);
    }

    protected function ensureDisposableTenantExists(): void
    {
        $this->ensureTenantExists($this->landlord->tenant_disposable);
    }

    protected function ensureTenantExists(TenantLabels $tenant): void
    {
        $showResponse = $this->tenantsShow($tenant->slug);
        if ($showResponse->status() === 200) {
            if (($showResponse->json('data.deleted_at')) !== null) {
                $this->tenantsRestore($tenant->slug)->assertStatus(200);
            }

            return;
        }

        $archivedResponse = $this->tenantsListArchived();
        $archivedTenant = collect($archivedResponse->json('data') ?? [])
            ->first(fn (array $item): bool => ($item['slug'] ?? null) === $tenant->slug);

        if (is_array($archivedTenant)) {
            $this->tenantsRestore($tenant->slug)->assertStatus(200);

            return;
        }

        $response = $this->tenantsCreate([
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
        ]);
        $response->assertStatus(201);

        $tenant->slug = (string) $response->json('data.slug');
        $tenant->id = (string) $response->json('data.id');
        $tenant->role_admin->id = (string) $response->json('data.role_admin_id');
        $tenant->role_admin->name = 'Admin';
    }
}
