<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountProfileDeletionGateConflictResponseTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->firstOrFail()->makeCurrent();

        AccountProfile::query()->delete();
        TenantProfileType::query()->delete();

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => false,
                'is_publicly_navigable' => false,
                'is_favoritable' => false,
                'is_publicly_discoverable' => false,
                'is_poi_enabled' => false,
                'has_events' => false,
            ],
        ]);

        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
                'has_events' => true,
            ],
        ]);
    }

    public function test_profile_update_returns_api_conflict_when_its_account_is_deletion_gated(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Deletion Gated Venue',
            'is_active' => true,
        ]);

        $this->account->setAttribute('account_profile_deletion_gate', [
            'attempt_id' => 'u07a-test-deletion-attempt',
            'attempt_generation' => 1,
        ]);
        $this->account->save();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profile->_id}",
            ['display_name' => 'Must Not Persist'],
            [...$this->getHeaders(), 'X-Request-Id' => 'u07a-gated-update-'.uniqid('', true)],
        );

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'A concurrency conflict occurred. Please try again.');

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);
        $this->assertSame('Deletion Gated Venue', (string) $profile->fresh()->display_name);
    }

    private function initializeSystem(): void
    {
        $this->ensureSystemInitialized();
    }
}
