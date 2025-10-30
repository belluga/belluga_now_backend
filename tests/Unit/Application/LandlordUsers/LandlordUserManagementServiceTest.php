<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LandlordUsers;

use App\Application\LandlordUsers\LandlordUserManagementService;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordUserManagementServiceTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    private LandlordUserManagementService $service;

    private LandlordUser $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshLandlordAndTenantDatabases();

        $this->service = $this->app->make(LandlordUserManagementService::class);

        $role = LandlordRole::create([
            'name' => 'Admin',
            'permissions' => ['*'],
        ]);

        $this->operator = LandlordUser::create([
            'name' => 'Operator',
            'emails' => ['operator@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
            'promotion_audit' => [],
            'role_id' => (string) $role->_id,
        ]);
    }

    protected function tearDown(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        parent::tearDown();
    }

    public function testPaginateReturnsUsers(): void
    {
        $paginator = $this->service->paginate(false, 15);

        $this->assertGreaterThanOrEqual(1, $paginator->total());
    }

    public function testCreatePersistsUser(): void
    {
        $role = LandlordRole::create([
            'name' => 'Support',
            'permissions' => ['*'],
        ]);

        $user = $this->service->create([
            'name' => 'Support User',
            'email' => 'support@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id, (string) $this->operator->_id);

        $this->assertInstanceOf(LandlordUser::class, $user);
        $this->assertEquals('support@example.org', $user->emails[0] ?? null);
    }

    public function testUpdateMutatesAttributes(): void
    {
        $user = LandlordUser::create([
            'name' => 'Editable User',
            'emails' => ['editable@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
            'promotion_audit' => [],
        ]);

        $updated = $this->service->update($user, ['name' => 'Edited User']);

        $this->assertEquals('Edited User', $updated->name);
    }

    public function testDeletePreventsSelfDeletion(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->delete($this->operator, $this->operator);
    }

    public function testDeleteSoftDeletesUser(): void
    {
        $user = LandlordUser::create([
            'name' => 'Disposable User',
            'emails' => ['disposable@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
            'promotion_audit' => [],
            'role_id' => $this->operator->role_id,
        ]);

        $this->service->delete($user, $this->operator);

        $this->assertSoftDeleted('landlord_users', ['_id' => $user->_id], 'landlord');
    }

    public function testRestoreBringsBackUser(): void
    {
        $user = LandlordUser::create([
            'name' => 'Restorable User',
            'emails' => ['restore@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
            'promotion_audit' => [],
            'role_id' => $this->operator->role_id,
        ]);

        $this->service->delete($user, $this->operator);

        $restored = $this->service->restore((string) $user->_id);

        $this->assertFalse($restored->trashed());
    }

    public function testForceDeleteRemovesUser(): void
    {
        $user = LandlordUser::create([
            'name' => 'Force Deletable User',
            'emails' => ['force@example.org'],
            'password' => 'Secret!234',
            'identity_state' => 'registered',
            'promotion_audit' => [],
            'role_id' => $this->operator->role_id,
        ]);

        $this->service->delete($user, $this->operator);
        $this->service->forceDelete((string) $user->_id);

        $this->assertDatabaseMissing('landlord_users', ['_id' => $user->_id], 'landlord');
    }
}
