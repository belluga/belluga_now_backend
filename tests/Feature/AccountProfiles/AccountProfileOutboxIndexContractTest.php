<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileOutboxIndexContractTest extends TestCaseTenant
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

    public function test_u07a_outbox_and_deletion_attempt_indexes_are_migration_provisioned(): void
    {
        $expected = [
            'account_profile_outbox' => [
                'uniq_account_profile_outbox_command_v1',
                'idx_account_profile_outbox_delivery_claim_v1',
                'idx_account_profile_outbox_profile_tuple_v1',
            ],
            'account_profile_projection_checkpoints' => [
                'idx_account_profile_projection_checkpoints_consumer_profile_v1',
            ],
            'account_profile_deletion_attempts' => [
                'idx_account_profile_deletion_attempts_claim_v1',
            ],
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
