<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountProfileDuplicateSlugValidationTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->firstOrFail()->makeCurrent();

        AccountProfile::query()->delete();
        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types')
            ->deleteMany([]);

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
                'is_favoritable' => true,
                'is_publicly_discoverable' => false,
                'is_poi_enabled' => false,
                'has_events' => false,
            ],
        ]);
    }

    public function test_account_onboarding_rejects_name_below_minimum_visible_length(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'ab',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
            ],
            $this->getHeaders(),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_account_profile_update_rejects_duplicate_slug(): void
    {
        $primary = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Primary Slug',
            'is_active' => true,
        ])->fresh();

        $otherAccount = Account::create([
            'name' => 'Account Slug Other',
            'document' => 'DOC-SLUG-OTHER',
        ]);
        $secondary = AccountProfile::create([
            'account_id' => (string) $otherAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Secondary Slug',
            'is_active' => true,
        ])->fresh();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $primary->_id,
            [
                'slug' => (string) ($secondary->slug ?? ''),
            ],
            $this->getHeaders(),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    public function test_account_profile_update_rejects_display_name_below_minimum_visible_length(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Visible Name',
            'is_active' => true,
        ])->fresh();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'display_name' => 'ab',
            ],
            $this->getHeaders(),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['display_name']);
    }
}
