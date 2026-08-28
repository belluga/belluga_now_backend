<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\MongoCommandTrace;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class NestedGroupLabelCopyCutoverMigrationTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    public function test_cutover_unsets_copies_idempotently_after_canonical_parity_inventory(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $tenantId = 'tenant-test';
        $database->selectCollection('account_profiles')->insertOne([
            '_id' => 'profile-1',
            'nested_profile_groups' => [['id' => 'artists', 'label' => 'Artists', 'order' => 0]],
        ]);
        $database->selectCollection('event_occurrences')->insertOne([
            '_id' => 'occurrence-1',
            'event_id' => 'event-1',
            'own_profile_groups' => [['_id' => 'artists', 'label' => 'Artists', 'order' => 0]],
            'profile_groups' => [['_id' => 'artists', 'label' => 'Artists', 'order' => 0]],
        ]);
        $database->selectCollection('accounts_nested')->insertMany([
            [
                '_id' => 'accounts-nested:head:account_profile:profile-1:artists',
                'tenant_id' => $tenantId,
                'parent_type' => 'account_profile', 'parent_id' => 'profile-1',
                'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'group_head',
            ],
            [
                '_id' => 'member-1', 'parent_type' => 'account_profile', 'parent_id' => 'profile-1',
                'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'member_row',
            ],
            [
                '_id' => 'accounts-nested:head:event_occurrence:occurrence-1:artists',
                'tenant_id' => $tenantId, 'event_id' => 'event-1',
                'parent_type' => 'event_occurrence', 'parent_id' => 'occurrence-1',
                'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'group_head',
            ],
            [
                '_id' => 'member-2', 'parent_type' => 'event_occurrence', 'parent_id' => 'occurrence-1',
                'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'member_row',
            ],
        ]);
        $database->selectCollection('account_profile_nested_public_member_projection')->insertOne([
            '_id' => 'projection-1', 'group_label' => 'Artists',
        ]);

        $migration = $this->migration();
        $migration->up();
        $migration->up();

        self::assertNull($database->selectCollection('accounts_nested')->findOne(['_id' => 'member-1'])['group_label'] ?? null);
        self::assertNull($database->selectCollection('accounts_nested')->findOne(['_id' => 'member-2'])['group_label'] ?? null);
        self::assertNull($database->selectCollection('account_profile_nested_public_member_projection')->findOne(['_id' => 'projection-1'])['group_label'] ?? null);
    }

    public function test_cutover_rejects_duplicate_or_non_parity_embedded_mirror(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $tenantId = 'tenant-test';
        $database->selectCollection('account_profiles')->insertOne([
            '_id' => 'profile-1',
            'nested_profile_groups' => [
                ['id' => 'artists', 'label' => 'Wrong'],
                ['id' => 'artists', 'label' => 'Artists'],
            ],
        ]);
        $database->selectCollection('accounts_nested')->insertOne([
            '_id' => 'accounts-nested:head:account_profile:profile-1:artists',
            'tenant_id' => $tenantId,
            'parent_type' => 'account_profile', 'parent_id' => 'profile-1',
            'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'group_head',
        ]);

        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    public function test_cutover_rejects_non_parity_in_either_event_embedded_array(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $tenantId = 'tenant-test';
        $database->selectCollection('event_occurrences')->insertOne([
            '_id' => 'occurrence-1',
            'event_id' => 'event-1',
            'own_profile_groups' => [['_id' => 'artists', 'label' => 'Artists']],
            'profile_groups' => [['_id' => 'artists', 'label' => 'Wrong']],
        ]);
        $database->selectCollection('accounts_nested')->insertOne([
            '_id' => 'accounts-nested:head:event_occurrence:occurrence-1:artists',
            'tenant_id' => $tenantId, 'event_id' => 'event-1',
            'parent_type' => 'event_occurrence', 'parent_id' => 'occurrence-1',
            'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'group_head',
        ]);

        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    public function test_cutover_rejects_orphan_member_rows_before_unsetting_any_copy(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $database->selectCollection('accounts_nested')->insertOne([
            '_id' => 'member-orphan', 'parent_type' => 'account_profile', 'parent_id' => 'profile-1',
            'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'member_row',
        ]);

        try {
            $this->migration()->up();
            self::fail('Expected orphan nested member row to reject the cutover.');
        } catch (RuntimeException) {
            self::assertSame('Artists', $database->selectCollection('accounts_nested')->findOne(['_id' => 'member-orphan'])['group_label']);
        }
    }

    public function test_cutover_rejects_account_embedded_group_without_a_canonical_head(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        DB::connection('tenant')->getDatabase()->selectCollection('account_profiles')->insertOne([
            '_id' => 'profile-1',
            'nested_profile_groups' => [['id' => 'artists', 'label' => 'Artists']],
        ]);

        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    public function test_cutover_rejects_event_embedded_group_without_a_canonical_head(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        DB::connection('tenant')->getDatabase()->selectCollection('event_occurrences')->insertOne([
            '_id' => 'occurrence-1',
            'own_profile_groups' => [['_id' => 'artists', 'label' => 'Artists']],
            'profile_groups' => [['_id' => 'artists', 'label' => 'Artists']],
        ]);

        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    public function test_cutover_rejects_a_head_without_the_current_tenant_selector_before_unset(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $database->selectCollection('account_profiles')->insertOne([
            '_id' => 'profile-1',
            'nested_profile_groups' => [['id' => 'artists', 'label' => 'Artists']],
        ]);
        $database->selectCollection('accounts_nested')->insertMany([
            [
                '_id' => 'accounts-nested:head:account_profile:profile-1:artists',
                'parent_type' => 'account_profile', 'parent_id' => 'profile-1',
                'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'group_head',
            ],
            [
                '_id' => 'member-1', 'parent_type' => 'account_profile', 'parent_id' => 'profile-1',
                'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'member_row',
            ],
        ]);

        try {
            $this->migration()->up();
            self::fail('Expected the missing tenant selector to reject the cutover.');
        } catch (RuntimeException) {
            self::assertSame('Artists', $database->selectCollection('accounts_nested')->findOne(['_id' => 'member-1'])['group_label']);
        }
    }

    public function test_cutover_rejects_mismatched_event_ownership_and_blank_embedded_identifiers(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $tenantId = 'tenant-test';
        $database->selectCollection('event_occurrences')->insertOne([
            '_id' => 'occurrence-1',
            'event_id' => 'event-actual',
            'own_profile_groups' => [['_id' => 'artists', 'label' => 'Artists'], ['_id' => '', 'label' => 'Malformed']],
            'profile_groups' => [['_id' => 'artists', 'label' => 'Artists']],
        ]);
        $database->selectCollection('accounts_nested')->insertOne([
            '_id' => 'accounts-nested:head:event_occurrence:occurrence-1:artists',
            'tenant_id' => $tenantId, 'event_id' => 'event-wrong',
            'parent_type' => 'event_occurrence', 'parent_id' => 'occurrence-1',
            'group_key' => 'artists', 'group_label' => 'Artists', 'doc_type' => 'group_head',
        ]);

        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    public function test_cutover_batches_high_cardinality_inventory_without_per_head_reads(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        $database = DB::connection('tenant')->getDatabase();
        $tenantId = 'tenant-test';
        $profiles = [];
        $heads = [];
        for ($index = 0; $index < 251; $index++) {
            $parentId = 'profile-'.$index;
            $groupId = 'group-'.$index;
            $profiles[] = [
                '_id' => $parentId,
                'nested_profile_groups' => [['id' => $groupId, 'label' => 'Label '.$index]],
            ];
            $heads[] = [
                '_id' => 'accounts-nested:head:account_profile:'.$parentId.':'.$groupId,
                'tenant_id' => $tenantId,
                'parent_type' => 'account_profile', 'parent_id' => $parentId,
                'group_key' => $groupId, 'group_label' => 'Label '.$index, 'doc_type' => 'group_head',
            ];
        }
        $database->selectCollection('account_profiles')->insertMany($profiles);
        $database->selectCollection('accounts_nested')->insertMany($heads);

        $trace = $this->captureMongoCommands(fn () => $this->migration()->up());

        self::assertLessThanOrEqual(3, $trace->countForCollection('account_profiles', 'find'));
        self::assertLessThanOrEqual(6, $trace->countForCollection('accounts_nested', 'find'));
    }

    public function test_cutover_rejects_blank_embedded_group_identifiers(): void
    {
        $this->refreshLandlordAndTenantDatabases();
        $this->makeMigrationTenantCurrent();
        DB::connection('tenant')->getDatabase()->selectCollection('account_profiles')->insertOne([
            '_id' => 'profile-blank-group',
            'nested_profile_groups' => [['id' => '', 'label' => 'Malformed']],
        ]);

        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path('database/migrations/tenants/2026_08_26_000100_remove_nested_group_label_copies.php');

        return $migration;
    }

    private function makeMigrationTenantCurrent(): void
    {
        $tenant = new Tenant;
        $tenant->setRawAttributes([
            '_id' => 'tenant-test',
            'slug' => 'tenant-test',
            'database' => (string) config('database.connections.tenant.database'),
        ], true);
        $tenant->exists = true;
        $tenant->makeCurrent();
    }

    /** @param callable():void $operation */
    private function captureMongoCommands(callable $operation): MongoCommandTrace
    {
        $client = DB::connection('tenant')->getClient();
        self::assertNotNull($client);
        $trace = new MongoCommandTrace;
        $client->addSubscriber($trace);

        try {
            $operation();
        } finally {
            $client->removeSubscriber($trace);
        }

        return $trace;
    }
}
