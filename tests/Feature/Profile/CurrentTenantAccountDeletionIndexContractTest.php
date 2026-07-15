<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class CurrentTenantAccountDeletionIndexContractTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->ensureSystemInitialized();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
    }

    public function test_every_u05_direct_selector_has_its_named_mongo_index(): void
    {
        $expected = [
            'accounts' => ['idx_accounts_personal_delete_owner_v1'],
            'account_profiles' => [
                'idx_account_profiles_owner_personal_v1',
                'idx_account_profiles_account_delete_v1',
                'idx_account_profiles_contact_source_delete_v1',
                'idx_account_profiles_nested_member_delete_v1',
            ],
            'account_users' => [
                'idx_account_users_account_role_delete_v1',
                'idx_account_users_merged_source_delete_v1',
            ],
            'contact_groups' => ['idx_contact_groups_owner_delete_v1'],
            'proximity_preferences' => ['idx_proximity_preferences_owner_delete_v1'],
            'attendance_commitments' => ['idx_attendance_commitments_user_delete_v1'],
            'contact_hash_directory' => ['idx_contact_hash_directory_matched_user_delete_v1'],
            'phone_otp_challenges' => ['idx_phone_otp_challenges_anonymous_delete_v1'],
            'identity_merge_audits' => [
                'idx_identity_merge_audits_canonical_delete_v1',
                'idx_identity_merge_audits_source_delete_v1',
            ],
            'merged_account_snapshots' => [
                'idx_merged_account_snapshots_source_delete_v1',
                'idx_merged_account_snapshots_target_delete_v1',
            ],
            'invite_edges' => ['idx_invite_edges_receiver_profile_delete_v1'],
            'inviteable_people_projection' => ['idx_inviteable_people_receiver_profile_delete_v1'],
            'push_message_actions' => ['idx_push_message_actions_user_delete_v1'],
        ];

        foreach ($expected as $collection => $indexNames) {
            $actual = iterator_to_array(
                DB::connection('tenant')
                    ->getMongoDB()
                    ->selectCollection($collection)
                    ->listIndexes(),
            );
            $actualNames = array_map(
                static fn (array|object $index): string => (string) ($index['name'] ?? ''),
                $actual,
            );

            foreach ($indexNames as $indexName) {
                $this->assertContains($indexName, $actualNames, "{$collection} misses {$indexName}");
            }
        }
    }
}
