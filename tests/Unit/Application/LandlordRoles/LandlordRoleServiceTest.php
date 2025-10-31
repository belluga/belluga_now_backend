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

        LandlordRole::create([
            'name' => 'Initial Role',
            'permissions' => ['*'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        parent::tearDown();
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

    public function testPaginateReturnsRoles(): void
    {
        $service = $this->app->make(LandlordRoleService::class);

        $paginator = $service->paginate(false, 15);

        $this->assertGreaterThanOrEqual(1, $paginator->total());
    }

    public function testDeleteByIdSoftDeletesRole(): void
    {
        $service = $this->app->make(LandlordRoleService::class);
        $fallback = LandlordRole::create([
            'name' => 'Fallback Reassignment',
            'permissions' => ['*'],
        ]);

        $role = LandlordRole::create([
            'name' => 'Disposable By Id',
            'permissions' => ['landlord-users:view'],
        ]);

        $service->deleteById((string) $role->_id, (string) $fallback->_id);

        $this->assertSoftDeleted('landlord_roles', ['name' => 'Disposable By Id']);
    }

    public function testRestoreByIdRevivesRole(): void
    {
        $service = $this->app->make(LandlordRoleService::class);
        $fallback = LandlordRole::create([
            'name' => 'Fallback Restore',
            'permissions' => ['*'],
        ]);

        $role = LandlordRole::create([
            'name' => 'Restorable Role',
            'permissions' => ['landlord-users:view'],
        ]);

        $service->deleteById((string) $role->_id, (string) $fallback->_id);
        $restored = $service->restoreById((string) $role->_id);

        $this->assertFalse($restored->trashed());
    }

    public function testForceDeleteByIdRemovesRole(): void
    {
        $service = $this->app->make(LandlordRoleService::class);
        $fallback = LandlordRole::create([
            'name' => 'Fallback Force',
            'permissions' => ['*'],
        ]);

        $role = LandlordRole::create([
            'name' => 'Force Role',
            'permissions' => ['landlord-users:view'],
        ]);

        $service->deleteById((string) $role->_id, (string) $fallback->_id);
        $service->forceDeleteById((string) $role->_id);

        $this->assertDatabaseMissing('landlord_roles', ['_id' => $role->_id], 'landlord');
    }

    public function testAssignRoleToUserAssociatesRole(): void
    {
        $service = $this->app->make(LandlordRoleService::class);
        $role = LandlordRole::create([
            'name' => 'Assignable Role',
            'permissions' => ['*'],
        ]);

        $user = LandlordUser::create([
            'name' => 'Role Receiver',
            'emails' => ['assign@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
            'promotion_audit' => [],
        ]);

        $service->assignRoleToUser((string) $role->_id, (string) $user->_id);
        $user->refresh();

        $this->assertEquals((string) $role->_id, $user->role_id);
    }
}
