<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Tenants;

use App\Application\Tenants\TenantRoleManagementService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Landlord\TenantRoleTemplate;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantRoleManagementServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    private Tenant $tenant;

    private TenantRoleManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $this->tenant = Tenant::query()->firstOrFail();
        $this->tenant->makeCurrent();

        $this->service = $this->app->make(TenantRoleManagementService::class);
    }

    public function testPaginateReturnsTenantRoles(): void
    {
        $paginator = $this->service->paginate($this->tenant, false);

        $this->assertGreaterThanOrEqual(1, $paginator->total());
    }

    public function testCreatePersistsRole(): void
    {
        $role = $this->service->create($this->tenant, [
            'name' => 'Support',
            'description' => 'Support role',
            'permissions' => ['tenant-users:view'],
        ]);

        $this->assertInstanceOf(TenantRoleTemplate::class, $role);
        $this->assertSame('Support', $role->name);
    }

    public function testUpdateMutatesPermissions(): void
    {
        $role = $this->service->create($this->tenant, [
            'name' => 'Editor',
            'permissions' => ['tenant-users:view'],
        ]);

        $updated = $this->service->update($this->tenant, (string) $role->_id, [
            'permissions' => [
                'add' => ['tenant-users:update'],
            ],
        ]);

        $this->assertContains('tenant-users:update', $updated->permissions);
    }

    public function testDeleteReassignsLandlordUsers(): void
    {
        $role = $this->service->create($this->tenant, [
            'name' => 'Temp',
            'permissions' => ['tenant-users:view'],
        ]);

        $fallback = $this->service->create($this->tenant, [
            'name' => 'Fallback',
            'permissions' => ['tenant-users:view'],
        ]);

        $this->service->delete($this->tenant, (string) $role->_id, (string) $fallback->_id);

        $this->assertSoftDeleted('tenant_role_templates', ['_id' => $role->_id], 'landlord');
    }

    public function testRestoreBringsBackRole(): void
    {
        $role = $this->service->create($this->tenant, [
            'name' => 'Archivable',
            'permissions' => ['tenant-users:view'],
        ]);

        $fallback = $this->service->create($this->tenant, [
            'name' => 'Fallback Archive',
            'permissions' => ['tenant-users:view'],
        ]);

        $this->service->delete($this->tenant, (string) $role->_id, (string) $fallback->_id);

        $restored = $this->service->restore($this->tenant, (string) $role->_id);

        $this->assertFalse($restored->trashed());
    }

    public function testForceDeleteRemovesRole(): void
    {
        $role = $this->service->create($this->tenant, [
            'name' => 'Temp Force',
            'permissions' => ['tenant-users:view'],
        ]);
        $fallback = $this->service->create($this->tenant, [
            'name' => 'Fallback Force',
            'permissions' => ['tenant-users:view'],
        ]);

        $this->service->delete($this->tenant, (string) $role->_id, (string) $fallback->_id);
        $this->service->forceDelete($this->tenant, (string) $role->_id);

        $this->assertDatabaseMissing('tenant_role_templates', ['_id' => $role->_id], 'landlord');
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Iota', 'subdomain' => 'tenant-iota'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'light_scheme_data' => ['primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                'dark_scheme_data' => ['primary_seed_color' => '#000', 'secondary_seed_color' => '#fff'],
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-iota.test']
        );

        $service->initialize($payload);
    }
}
