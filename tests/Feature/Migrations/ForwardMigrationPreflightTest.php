<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

/**
 * Executes the frozen forward preflights against the local Docker Mongo lane.
 * These tests deliberately invoke the migration classes directly: the migration
 * ledger is not the subject here, and a failed preflight must happen before DDL.
 */
final class ForwardMigrationPreflightTest extends TestCase
{
    use RefreshLandlordAndTenantDatabases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshLandlordAndTenantDatabases();
    }

    public function test_password_retirement_refuses_non_empty_tenant_tokens_without_dropping_them(): void
    {
        $tenant = DB::connection('tenant')->getDatabase();
        $tenant->createCollection('password_reset_tokens');
        $tenant->selectCollection('password_reset_tokens')->insertOne(['token' => 'must-remain']);

        try {
            $this->migration('database/migrations/tenants/2026_09_10_000100_drop_legacy_tenant_password_reset_tokens_collection.php')->up();
            self::fail('Expected the frozen password-token preflight to reject non-empty tenant data.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.password_reset_tokens.empty_v1', $exception->getMessage());
        }

        self::assertSame(1, $tenant->selectCollection('password_reset_tokens')->countDocuments());
    }

    public function test_events_forward_rejects_same_name_conflict_before_creating_any_target_index(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('events');
        $events = $database->selectCollection('events');
        $events->createIndex(['publication.status' => 1], [
            'name' => 'publication.status_1_publication.publish_at_1__id_1',
        ]);

        try {
            $this->migration('packages/belluga/belluga_events/database/migrations/2026_09_10_000200_reconcile_events_collection_indexes.php')->up();
            self::fail('Expected the Events index conflict preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.events.index_conflicts_v1', $exception->getMessage());
        }

        self::assertCount(2, iterator_to_array($events->listIndexes()));
    }

    public function test_invite_forward_rejects_missing_receiver_profile_before_index_ddl(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('invite_edges');
        $invites = $database->selectCollection('invite_edges');
        $invites->insertOne([
            'event_id' => 'event-1',
            'occurrence_id' => 'occurrence-1',
            'credited_acceptance' => false,
        ]);

        try {
            $this->migration('packages/belluga/belluga_invites/database/migrations/2026_09_10_000300_reconcile_invite_receiver_profile_indexes.php')->up();
            self::fail('Expected the Invite receiver-profile preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.invite_edges.receiver_profile_unique_v1', $exception->getMessage());
        }

        self::assertSame(['_id_'], $this->indexNames($invites));
    }

    public function test_invite_forward_rejects_same_key_with_incompatible_options_before_index_ddl(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('invite_edges');
        $invites = $database->selectCollection('invite_edges');
        $invites->createIndex(
            ['receiver_account_profile_id' => 1, 'event_id' => 1, 'occurrence_id' => 1, 'credited_acceptance' => 1, '_id' => 1],
            ['name' => 'incompatible_receiver_profile_lookup', 'unique' => true],
        );
        $before = $this->indexInfoByName($invites);

        try {
            $this->migration('packages/belluga/belluga_invites/database/migrations/2026_09_10_000300_reconcile_invite_receiver_profile_indexes.php')->up();
            self::fail('Expected the Invite same-key conflict preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.invite_edges.receiver_profile_unique_v1', $exception->getMessage());
        }

        self::assertSame($before, $this->indexInfoByName($invites));
    }

    public function test_invite_forward_preflights_winner_conflict_before_creating_lookup_indexes(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('invite_edges');
        $invites = $database->selectCollection('invite_edges');
        $invites->createIndex(
            ['receiver_user_id' => 1],
            ['name' => 'uq_invite_edges_profile_occurrence_credited_winner'],
        );
        $before = $this->indexInfoByName($invites);

        try {
            $this->migration('packages/belluga/belluga_invites/database/migrations/2026_09_10_000300_reconcile_invite_receiver_profile_indexes.php')->up();
            self::fail('Expected the Invite winner-index preflight to fail before DDL.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.invite_edges.receiver_profile_unique_v1', $exception->getMessage());
        }

        self::assertSame($before, $this->indexInfoByName($invites));
    }

    public function test_invite_forward_rejects_duplicate_receiver_principal_before_any_index_ddl(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('invite_edges');
        $invites = $database->selectCollection('invite_edges');
        $shared = [
            'receiver_account_profile_id' => 'profile-1',
            'event_id' => 'event-1',
            'occurrence_id' => 'occurrence-1',
            'credited_acceptance' => false,
            'inviter_principal' => ['kind' => 'account_profile', 'principal_id' => 'profile-2'],
        ];
        $invites->insertMany([
            $shared + ['receiver_user_id' => 'user-1'],
            $shared + ['receiver_user_id' => 'user-2'],
        ]);
        $before = $this->indexInfoByName($invites);

        try {
            $this->migration('packages/belluga/belluga_invites/database/migrations/2026_09_10_000300_reconcile_invite_receiver_profile_indexes.php')->up();
            self::fail('Expected the Invite receiver-principal duplicate preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.invite_edges.receiver_profile_unique_v1', $exception->getMessage());
        }

        self::assertSame($before, $this->indexInfoByName($invites));
        self::assertSame(2, $invites->countDocuments());
    }

    public function test_events_and_invites_forwards_create_the_frozen_ordered_index_contracts(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('events');
        $events = $database->selectCollection('events');
        $this->migration('packages/belluga/belluga_events/database/migrations/2026_09_10_000200_reconcile_events_collection_indexes.php')->up();
        $eventIndexes = $this->indexInfoByName($events);
        self::assertSame(
            ['publication.status' => 1, 'publication.publish_at' => 1, '_id' => 1],
            $eventIndexes['publication.status_1_publication.publish_at_1__id_1']['key'],
        );
        self::assertFalse($eventIndexes['publication.status_1_publication.publish_at_1__id_1']['unique'] ?? false);

        $database->dropCollection('invite_edges');
        $invites = $database->selectCollection('invite_edges');
        $this->migration('packages/belluga/belluga_invites/database/migrations/2026_09_10_000300_reconcile_invite_receiver_profile_indexes.php')->up();
        $indexes = $this->indexInfoByName($invites);
        self::assertSame(
            ['receiver_account_profile_id' => 1, 'event_id' => 1, 'occurrence_id' => 1, 'credited_acceptance' => 1, '_id' => 1],
            $indexes['receiver_account_profile_id_1_event_id_1_occurrence_id_1_credited_acceptance_1__id_1']['key'],
        );
        self::assertSame(
            ['receiver_user_id' => 1, 'event_id' => 1, 'occurrence_id' => 1, 'status' => 1, 'created_at' => 1, '_id' => 1],
            $indexes['receiver_user_id_1_event_id_1_occurrence_id_1_status_1_created_at_1__id_1']['key'],
        );
        self::assertTrue($indexes['uq_invite_edges_target_receiver_profile_principal_v1']['unique'] ?? false);
        self::assertSame(
            ['receiver_account_profile_id' => ['$type' => 'string']],
            $indexes['uq_invite_edges_target_receiver_profile_principal_v1']['partialFilterExpression'],
        );
        self::assertTrue($indexes['uq_invite_edges_profile_occurrence_credited_winner']['unique'] ?? false);
    }

    public function test_nested_profile_retirement_requires_the_literal_canonical_owner_before_drop(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('account_profiles');
        $database->dropCollection('accounts_nested');
        $profiles = $database->selectCollection('account_profiles');
        $profiles->createIndex(
            ['nested_profile_groups.account_profile_ids' => 1, '_id' => 1],
            ['name' => 'idx_account_profiles_nested_member_delete_v1'],
        );
        $database->createCollection('accounts_nested');

        try {
            $this->migration('database/migrations/tenants/2026_09_10_000400_drop_retired_nested_profile_delete_index.php')->up();
            self::fail('Expected the nested owner preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.account_profiles.nested_delete_retirement_v1', $exception->getMessage());
        }

        self::assertContains('idx_account_profiles_nested_member_delete_v1', $this->indexNames($profiles));
    }

    public function test_nested_profile_retirement_rejects_same_name_wrong_key_owner_without_mutation(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('account_profiles');
        $database->dropCollection('accounts_nested');
        $profiles = $database->selectCollection('account_profiles');
        $profiles->insertOne(['display_name' => 'must-remain']);
        $profiles->createIndex(
            ['nested_profile_groups.account_profile_ids' => 1, '_id' => 1],
            ['name' => 'idx_account_profiles_nested_member_delete_v1'],
        );
        $owner = $database->selectCollection('accounts_nested');
        $owner->createIndex(['unrelated' => 1], ['name' => 'idx_accounts_nested_member_lookup_v1']);
        $before = $this->databaseState($database);

        try {
            $this->migration('database/migrations/tenants/2026_09_10_000400_drop_retired_nested_profile_delete_index.php')->up();
            self::fail('Expected the nested owner definition preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.account_profiles.nested_delete_retirement_v1', $exception->getMessage());
        }

        self::assertSame($before, $this->databaseState($database));
    }

    public function test_nested_profile_retirement_rejects_incompatible_retired_index_options_without_mutation(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('account_profiles');
        $database->dropCollection('accounts_nested');
        $profiles = $database->selectCollection('account_profiles');
        $profiles->createIndex(
            ['nested_profile_groups.account_profile_ids' => 1, '_id' => 1],
            ['name' => 'idx_account_profiles_nested_member_delete_v1', 'unique' => true],
        );
        $owner = $database->selectCollection('accounts_nested');
        $owner->createIndex(
            ['tenant_id' => 1, 'nested_profile.id' => 1, 'doc_type' => 1, '_id' => 1],
            [
                'name' => 'idx_accounts_nested_member_lookup_v1',
                'partialFilterExpression' => ['doc_type' => 'member_row'],
            ],
        );
        $before = $this->databaseState($database);

        try {
            $this->migration('database/migrations/tenants/2026_09_10_000400_drop_retired_nested_profile_delete_index.php')->up();
            self::fail('Expected the retired nested index options preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.account_profiles.nested_delete_retirement_v1', $exception->getMessage());
        }

        self::assertSame($before, $this->databaseState($database));
    }

    public function test_receipts_forward_refuses_an_incompatible_existing_index_without_mutation(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('account_profile_command_receipts');
        $receipts = $database->selectCollection('account_profile_command_receipts');
        $receipts->createIndex(['request_id' => 1], ['name' => 'request_id_1']);

        try {
            $this->migration('database/migrations/tenants/2026_09_10_000450_create_account_profile_command_receipts_collection.php')->up();
            self::fail('Expected the receipts compatibility preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.account_profile_command_receipts.compatible_v1', $exception->getMessage());
        }

        self::assertSame(['_id_', 'request_id_1'], $this->indexNames($receipts));
    }

    public function test_receipts_forward_refuses_incompatible_collection_options_without_mutation(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('account_profile_command_receipts');
        $database->createCollection('account_profile_command_receipts', [
            'capped' => true,
            'size' => 1048576,
            'max' => 10,
        ]);
        $database->selectCollection('account_profile_command_receipts')->insertOne([
            '_id' => 'receipt-must-remain',
        ]);
        $before = $this->collectionDefinition($database, 'account_profile_command_receipts');

        try {
            $this->migration('database/migrations/tenants/2026_09_10_000450_create_account_profile_command_receipts_collection.php')->up();
            self::fail('Expected the receipts collection options preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.account_profile_command_receipts.compatible_v1', $exception->getMessage());
        }

        self::assertSame($before, $this->collectionDefinition($database, 'account_profile_command_receipts'));
    }

    public function test_invite_forward_rejects_noncanonical_legacy_index_options_without_mutation(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->dropCollection('invite_edges');
        $invites = $database->selectCollection('invite_edges');
        $invites->createIndex(
            ['receiver_user_id' => 1, 'event_id' => 1, 'occurrence_id' => 1],
            [
                'name' => 'uq_invite_edges_user_occurrence_credited_winner',
                'unique' => true,
                'partialFilterExpression' => [
                    'receiver_user_id' => ['$exists' => true],
                    'credited_acceptance' => true,
                ],
                'collation' => ['locale' => 'en', 'strength' => 2],
            ],
        );
        $before = $this->databaseState($database);

        try {
            $this->migration('packages/belluga/belluga_invites/database/migrations/2026_09_10_000300_reconcile_invite_receiver_profile_indexes.php')->up();
            self::fail('Expected the Invite legacy options preflight to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tenant.invite_edges.receiver_profile_unique_v1', $exception->getMessage());
        }

        self::assertSame($before, $this->databaseState($database));
    }

    public function test_all_three_retired_applied_tombstones_are_schema_and_data_zero_operations(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $database->selectCollection('tombstone_operation_sentinel')->insertOne(['value' => 'unchanged']);
        $before = $this->databaseState($database);

        foreach ([
            'database/migrations/tenants/2026_03_01_000100_create_ticketing_core_collections.php',
            'database/migrations/tenants/2026_07_20_000400_create_account_profile_nested_public_member_projection_indexes.php',
            'packages/belluga/belluga_events/database/migrations/2026_07_21_000800_create_event_profile_group_members_collection.php',
        ] as $path) {
            $migration = $this->migration($path);
            $migration->up();
            $migration->down();
        }

        self::assertSame($before, $this->databaseState($database));
    }

    public function test_map_projection_forward_converges_activity_and_replaces_the_geo_index_idempotently(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        foreach (['map_pois', 'account_profiles', 'accounts'] as $collection) {
            $database->dropCollection($collection);
        }
        $pois = $database->selectCollection('map_pois');
        $profiles = $database->selectCollection('account_profiles');
        $accounts = $database->selectCollection('accounts');
        $pois->createIndex(['location' => '2dsphere'], ['name' => 'location_2dsphere']);

        $publishedAccountId = new ObjectId();
        $draftAccountId = new ObjectId();
        $activePublishedProfileId = new ObjectId();
        $activeDraftProfileId = new ObjectId();
        $inactivePublishedProfileId = new ObjectId();
        $accounts->insertMany([
            ['_id' => $publishedAccountId, 'publication' => ['status' => 'published']],
            ['_id' => $draftAccountId, 'publication' => ['status' => 'draft']],
        ]);
        $profiles->insertMany([
            ['_id' => $activePublishedProfileId, 'account_id' => (string) $publishedAccountId, 'is_active' => true],
            ['_id' => $activeDraftProfileId, 'account_id' => (string) $draftAccountId, 'is_active' => true],
            ['_id' => $inactivePublishedProfileId, 'account_id' => (string) $publishedAccountId, 'is_active' => false],
        ]);
        foreach ([
            [(string) $activePublishedProfileId, false],
            [(string) $activeDraftProfileId, true],
            [(string) $inactivePublishedProfileId, true],
            [(string) new ObjectId(), true],
        ] as [$refId, $isActive]) {
            $pois->insertOne([
                'ref_type' => 'account_profile',
                'ref_id' => $refId,
                'location' => ['type' => 'Point', 'coordinates' => [-40.0, -20.0]],
                'is_active' => $isActive,
            ]);
        }

        $migration = $this->migration(
            'packages/belluga/belluga_map_pois/database/migrations/2026_09_10_000500_converge_bounded_map_read_projection.php'
        );
        $migration->up();
        $migration->up();

        self::assertTrue((bool) $pois->findOne(['ref_id' => (string) $activePublishedProfileId])['is_active']);
        self::assertFalse((bool) $pois->findOne(['ref_id' => (string) $activeDraftProfileId])['is_active']);
        self::assertFalse((bool) $pois->findOne(['ref_id' => (string) $inactivePublishedProfileId])['is_active']);
        self::assertSame(1, $pois->countDocuments(['ref_type' => 'account_profile', 'is_active' => true]));
        $indexes = $this->indexInfoByName($pois);
        self::assertSame(
            ['location' => '2dsphere', 'is_active' => 1],
            $indexes['idx_map_pois_location_active_v1']['key'],
        );
        self::assertArrayNotHasKey('location_2dsphere', $indexes);
    }

    private function migration(string $path): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path($path);

        return $migration;
    }

    /** @return list<string> */
    private function indexNames(object $collection): array
    {
        $names = array_map(static fn (object $index): string => $index->getName(), iterator_to_array($collection->listIndexes()));
        sort($names);

        return $names;
    }

    /** @return array<string, array<string, mixed>> */
    private function indexInfoByName(object $collection): array
    {
        $result = [];
        foreach ($collection->listIndexes() as $index) {
            $info = json_decode(json_encode($index, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $info['key'] = json_decode(json_encode($index->getKey(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            foreach (['unique', 'partialFilterExpression'] as $option) {
                if (isset($index[$option])) {
                    $info[$option] = $index[$option];
                }
            }
            $result[$index->getName()] = $info;
        }

        return $result;
    }

    /** @return array{type:string,options:array<string,mixed>,documents:int,indexes:array<string,array<string,mixed>>} */
    private function collectionDefinition(object $database, string $name): array
    {
        $info = null;
        foreach ($database->listCollections(['filter' => ['name' => $name]]) as $collectionInfo) {
            $info = $collectionInfo;
        }
        self::assertNotNull($info);
        $collection = $database->selectCollection($name);

        return [
            'type' => $info->getType(),
            'options' => json_decode(json_encode($info->getOptions(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            'documents' => $collection->countDocuments(),
            'indexes' => $this->indexInfoByName($collection),
        ];
    }

    /** @return array<string, array{documents:int,indexes:array<string, array<string, mixed>>}> */
    private function databaseState(object $database): array
    {
        $state = [];
        foreach ($database->listCollectionNames() as $name) {
            $collection = $database->selectCollection($name);
            $state[$name] = [
                'documents' => $collection->countDocuments(),
                'indexes' => $this->indexInfoByName($collection),
            ];
        }
        ksort($state);

        return $state;
    }
}
