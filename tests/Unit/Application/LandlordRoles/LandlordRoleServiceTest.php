<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LandlordRoles;

use App\Application\LandlordRoles\LandlordRoleService;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordRoleServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
    }

    public function testCreatesLandlordRole(): void
    {
        $service = $this->app->make(LandlordRoleService::class);

        $role = $service->create([
            'name' => 'Support Role',
            'permissions' => ['landlord-users:view'],
        ]);

        $this->assertDatabaseHas('landlord_roles', ['name' => 'Support Role'], 'landlord');
        $this->assertEquals(['landlord-users:view'], $role->permissions);
    }

    public function testUpdatesPermissionsWithMutation(): void
    {
        $service = $this->app->make(LandlordRoleService::class);
        $role = LandlordRole::create([
            'name' => 'Mutable Role',
            'permissions' => ['profile:view'],
        ]);

        $updated = $service->update($role, [
            'permissions' => [
                'add' => ['profile:update'],
                'remove' => ['profile:view'],
            ],
        ]);

        $this->assertEquals(['profile:update'], $updated->permissions);
    }

    public function testDeletesRoleAndReassignsUsers(): void
    {
        $service = $this->app->make(LandlordRoleService::class);
        $fallback = LandlordRole::create([
            'name' => 'Fallback',
            'permissions' => ['*'],
        ]);

        $role = LandlordRole::create([
            'name' => 'Disposable',
            'permissions' => ['landlord-users:view'],
        ]);

        LandlordUser::create([
            'name' => 'To Reassign',
            'emails' => ['reassign@example.org'],
            'password' => 'secret',
            'identity_state' => 'registered',
            'role_id' => (string) $role->_id,
            'promotion_audit' => [],
        ]);

        $service->deleteWithReassignment($role, (string) $fallback->_id);

        $this->assertSoftDeleted('landlord_roles', ['name' => 'Disposable']);
    }
}
