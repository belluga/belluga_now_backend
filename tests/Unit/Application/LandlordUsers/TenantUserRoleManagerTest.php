<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LandlordUsers;

use App\Application\LandlordUsers\TenantUserRoleManager;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class TenantUserRoleManagerTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
    }

    public function testAssignsAndRevokesTenantRoles(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant Assign',
            'subdomain' => 'tenant-assign',
        ]);

        $template = $tenant->roleTemplates()->create([
            'name' => 'Managers',
            'permissions' => ['accounts:view'],
        ]);

        $user = LandlordUser::create([
            'name' => 'Tenant Support',
            'emails' => ['tenant-support@example.org'],
            'password' => 'secret',
            'identity_state' => 'registered',
            'promotion_audit' => [],
        ]);

        $manager = $this->app->make(TenantUserRoleManager::class);

        $manager->assign((string) $user->_id, (string) $template->_id, $tenant);
        $this->assertCount(1, $user->fresh()->tenant_roles ?? []);

        $manager->revoke((string) $user->_id, (string) $template->_id, $tenant);
        $this->assertCount(0, $user->fresh()->tenant_roles ?? []);
    }
}
