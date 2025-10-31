<?php

declare(strict_types=1);

namespace Tests\Unit\Application\LandlordUsers;

use App\Application\LandlordUsers\LandlordUserCreator;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class LandlordUserCreatorTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
    }

    public function testCreatesUserWithPromotionAudit(): void
    {
        $role = LandlordRole::create([
            'name' => 'Support Role',
            'permissions' => ['landlord-users:view'],
        ]);

        $creator = $this->app->make(LandlordUserCreator::class);

        $user = $creator->create(
            [
                'name' => 'New Support',
                'email' => 'support@example.org',
                'password' => 'Secret!234',
            ],
            (string) $role->_id,
            operatorId: '507f1f77bcf86cd799439011'
        );

        $this->assertDatabaseCount('landlord_users', 1, 'landlord');
        $this->assertEquals('New Support', $user->name);
        $this->assertEquals('registered', $user->identity_state);
        $this->assertCount(1, $user->promotion_audit);
        $this->assertEquals('anonymous', $user->promotion_audit[0]['from_state']);
        $this->assertEquals('registered', $user->promotion_audit[0]['to_state']);
    }
}
