<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Landlord\Tenant;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Objective, dump-metadata-only replay.  It intentionally never imports a
 * document collection: the two supplied schema exports contain collection
 * options and index definitions only and the ledger exports contain only
 * migration/batch rows.
 */
final class MigrationSchemaConvergenceTest extends TestCase
{
    private const DUMPS = __DIR__.'/Fixtures/production';

    private const RUNTIME_EXCLUSIONS = ['landlord' => ['application_logs'], 'tenant' => ['cache']];

    private const RETIRED = [
        'ticket_checkin_logs', 'ticket_event_templates', 'ticket_holds', 'ticket_inventory_states',
        'ticket_order_items', 'ticket_orders', 'ticket_outbox_events', 'ticket_products',
        'ticket_promotion_redemptions', 'ticket_promotions', 'ticket_queue_entries',
        'ticket_unit_audit_events', 'ticket_units', 'account_profile_nested_public_member_projection',
        'event_profile_group_members',
    ];

    /** @var list<string> */
    private array $owned = [];

    /** @var array<string, string> */
    private array $baseDsns = [];

    private string $fixturePrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePrefix = 'migration_objective_'.bin2hex(random_bytes(8));
        $this->assertSafeLocalTopology();
        $this->configureOwnedConnections();
    }

    protected function tearDown(): void
    {
        // A failed assertion must not turn an unknown database into a cleanup target.
        foreach ($this->owned as $database) {
            $client = DB::connection('mongodb')->getMongoClient();
            $fixture = $client->selectDatabase($database);
            if ($fixture->selectCollection('__migration_objective_owner')->countDocuments([
                'run' => $this->fixturePrefix,
            ]) === 1) {
                $fixture->drop();
            }
        }

        parent::tearDown();
    }

    public function test_fresh_and_production_ledger_replays_converge_and_are_idempotent(): void
    {
        $this->assertDumpContract();
        $sentinel = $this->createOutOfScopeSentinel();

        [$freshLandlord, $freshTenant] = $this->newFixturePair('fresh');
        $this->seedSentinel($freshLandlord, 'fresh-landlord');
        $this->seedSentinel($freshTenant, 'fresh-tenant');
        $this->migrateComplete($freshLandlord, $freshTenant);
        self::assertSame(14, $this->ledgerCount($freshLandlord));
        self::assertSame(83, $this->ledgerCount($freshTenant));
        self::assertSame([], $this->retiredSchema($freshLandlord));
        self::assertSame([], $this->retiredSchema($freshTenant));
        $this->assertSentinel($freshLandlord, 'fresh-landlord');
        $this->assertSentinel($freshTenant, 'fresh-tenant');

        [$productionLandlord, $productionTenant] = $this->newFixturePair('production');
        $this->importLedger($productionLandlord, 'landlord.migrations.json');
        $this->importLedger($productionTenant, 'tenant_boora.migrations.json');
        self::assertSame(13, $this->ledgerCount($productionLandlord));
        self::assertSame(77, $this->ledgerCount($productionTenant));
        $this->importSchema($productionLandlord, 'landlord.schema.json');
        $this->importSchema($productionTenant, 'tenant_boora.schema.json');
        $productionRetiredBefore = $this->retiredSchema($productionTenant);
        $expectedRetired = self::RETIRED;
        sort($expectedRetired);
        self::assertSame($expectedRetired, array_keys($productionRetiredBefore));
        self::assertSame([], $this->retiredSchema($productionLandlord));
        $this->seedSentinel($productionLandlord, 'production-landlord');
        $this->seedSentinel($productionTenant, 'production-tenant');
        self::assertContains('idx_account_profile_types_capability_has_content_v1', $this->indexNames($productionTenant, 'account_profile_types'));
        self::assertSame(['_id_'], $this->indexNames($productionTenant, 'account_profile_command_receipts'));
        $this->migrateComplete($productionLandlord, $productionTenant);
        self::assertSame(14, $this->ledgerCount($productionLandlord));
        self::assertSame(83, $this->ledgerCount($productionTenant));
        self::assertSame($productionRetiredBefore, $this->retiredSchema($productionTenant));
        $this->assertSentinel($productionLandlord, 'production-landlord');
        $this->assertSentinel($productionTenant, 'production-tenant');

        $fresh = [$this->normalizedSchema($freshLandlord, 'landlord', false), $this->normalizedSchema($freshTenant, 'tenant', false)];
        $production = [$this->normalizedSchema($productionLandlord, 'landlord', true), $this->normalizedSchema($productionTenant, 'tenant', true)];
        self::assertSame($fresh, $production, 'Fresh and production-ledger histories must converge.');
        $evidence = [
            'frozen' => [
                'landlord' => ['counts' => [18, 70, 1], 'fingerprint' => '1888c52bc3fb0920e2d600eb6b3ce44b80bc12be731b441fbd5d6db90566b956'],
                'tenant' => ['counts' => [47, 291, 1], 'fingerprint' => '96bd209691969954445a5f3e3bd1fa17ba8b214c6aa044e0bab291b8e341dd2d'],
            ],
            'actual' => [
                'fresh' => [
                    'landlord' => ['counts' => $this->schemaCounts($fresh[0], $freshLandlord, 'landlord'), 'fingerprint' => $this->fingerprint($fresh[0])],
                    'tenant' => ['counts' => $this->schemaCounts($fresh[1], $freshTenant, 'tenant'), 'fingerprint' => $this->fingerprint($fresh[1])],
                ],
                'production_after_forwards' => [
                    'landlord' => ['counts' => $this->schemaCounts($production[0], $productionLandlord, 'landlord'), 'fingerprint' => $this->fingerprint($production[0])],
                    'tenant' => ['counts' => $this->schemaCounts($production[1], $productionTenant, 'tenant'), 'fingerprint' => $this->fingerprint($production[1])],
                ],
            ],
            'nominal_delta' => [
                'tenant_indexes' => 'frozen 292 - actual 291 = 1',
                'production_initial_has_content_index' => true,
                'after_current_tail_has_content_index' => in_array('idx_account_profile_types_capability_has_content_v1', $this->indexNames($productionTenant, 'account_profile_types'), true),
                'production_command_receipts_indexes' => $this->indexNames($productionTenant, 'account_profile_command_receipts'),
            ],
            'ledgers' => ['fresh' => [$this->ledgerCount($freshLandlord), $this->ledgerCount($freshTenant)], 'production_after_forwards' => [$this->ledgerCount($productionLandlord), $this->ledgerCount($productionTenant)]],
            'excluded' => [
                'fresh_retired' => ['landlord' => [], 'tenant' => []],
                'production_retired_tenant' => $productionRetiredBefore,
                'runtime' => [
                    'fresh_landlord' => $this->selectedSchema($freshLandlord, self::RUNTIME_EXCLUSIONS['landlord']),
                    'fresh_tenant' => $this->selectedSchema($freshTenant, self::RUNTIME_EXCLUSIONS['tenant']),
                    'production_landlord' => $this->selectedSchema($productionLandlord, self::RUNTIME_EXCLUSIONS['landlord']),
                    'production_tenant' => $this->selectedSchema($productionTenant, self::RUNTIME_EXCLUSIONS['tenant']),
                ],
            ],
        ];
        fwrite(STDERR, 'MIGRATION_CONVERGENCE_EVIDENCE='.json_encode($evidence, JSON_THROW_ON_ERROR).PHP_EOL);

        $before = [$this->ledger($productionLandlord), $this->ledger($productionTenant), $production];
        $this->migrateComplete($productionLandlord, $productionTenant);
        self::assertSame($before, [$this->ledger($productionLandlord), $this->ledger($productionTenant), [
            $this->normalizedSchema($productionLandlord, 'landlord', true), $this->normalizedSchema($productionTenant, 'tenant', true),
        ]]);
        self::assertSame(1, $sentinel->countDocuments(['run' => $this->fixturePrefix, 'value' => 'outside-objective-fixture']));
        $mismatches = [];
        foreach (['landlord', 'tenant'] as $scope) {
            $actual = $evidence['actual']['fresh'][$scope];
            $frozen = $evidence['frozen'][$scope];
            if ($actual['counts'] !== $frozen['counts']) {
                $mismatches["$scope.counts"] = ['expected' => $frozen['counts'], 'actual' => $actual['counts']];
            }
            if ($actual['fingerprint'] !== $frozen['fingerprint']) {
                $mismatches["$scope.fingerprint"] = ['expected' => $frozen['fingerprint'], 'actual' => $actual['fingerprint']];
            }
        }
        self::assertSame([], $mismatches, json_encode(['evidence' => $evidence, 'mismatches' => $mismatches], JSON_THROW_ON_ERROR));
    }

    public function test_identity_controls_reject_srv_remote_alias_wrong_database_and_missing_marker_before_mutation(): void
    {
        $sentinel = $this->createOutOfScopeSentinel();
        foreach ([
            'mongodb+srv://cluster.example.invalid/objective',
            'mongodb://remote.example.invalid/objective',
            'mongodb://mongo:27017/not_'.$this->fixturePrefix,
        ] as $dsn) {
            self::assertFalse($this->isSafeFixtureDsn($dsn, $this->fixturePrefix.'_landlord'));
        }

        $database = DB::connection('mongodb')->getMongoClient()->selectDatabase($this->fixturePrefix.'_unmarked');
        self::assertSame(0, $database->selectCollection('__migration_objective_owner')->countDocuments());
        self::assertFalse($this->isOwnedFixture($database, $this->fixturePrefix.'_unmarked'));
        $callbackRan = false;
        try {
            $this->withDatabases($database, DB::connection('tenant')->getDatabase(), static function () use (&$callbackRan): void {
                $callbackRan = true;
            });
            self::fail('Expected an unmarked database to be rejected before migration.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('owned, distinct databases', $exception->getMessage());
        }
        self::assertFalse($callbackRan);

        $alias = $this->ownedDatabase(DB::connection('mongodb')->getMongoClient(), $this->fixturePrefix.'_alias');
        try {
            $this->withDatabases($alias, $alias, static function () use (&$callbackRan): void {
                $callbackRan = true;
            });
            self::fail('Expected landlord/tenant database aliasing to be rejected before migration.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('owned, distinct databases', $exception->getMessage());
        }
        self::assertFalse($callbackRan);
        self::assertSame(1, $sentinel->countDocuments(['run' => $this->fixturePrefix, 'value' => 'outside-objective-fixture']));
    }

    public function test_missing_landlord_settings_path_is_rejected_before_replay(): void
    {
        $paths = (array) config('multitenancy.landlord_migration_paths');
        self::assertContains('packages/belluga/belluga_settings/database/migrations_landlord', $paths);
        config(['multitenancy.landlord_migration_paths' => ['database/migrations/landlord']]);
        $this->expectException(\RuntimeException::class);
        $this->assertCompletePathInventory();
    }

    public function test_two_tenant_databases_are_isolated_and_tombstones_are_zero_operation(): void
    {
        [$landlord, $tenantA] = $this->newFixturePair('isolation-a');
        [, $tenantB] = $this->newFixturePair('isolation-b');
        $this->seedSentinel($landlord, 'isolation-landlord');
        $this->seedSentinel($tenantA, 'isolation-a');
        $this->seedSentinel($tenantB, 'isolation-b');
        $this->migrateComplete($landlord, $tenantA);
        $this->migrateTenant($tenantB);
        $tenantA->selectCollection('objective_sentinel')->insertOne(['tenant' => 'a']);
        self::assertSame(1, $tenantA->selectCollection('objective_sentinel')->countDocuments());
        self::assertSame(0, $tenantB->selectCollection('objective_sentinel')->countDocuments());
        self::assertSame([], $this->retiredSchema($tenantA));
        self::assertSame([], $this->retiredSchema($tenantB));
        $this->assertSentinel($landlord, 'isolation-landlord');
        $this->assertSentinel($tenantA, 'isolation-a');
        $this->assertSentinel($tenantB, 'isolation-b');
        self::assertSame(83, $this->ledgerCount($tenantA));
        self::assertSame(83, $this->ledgerCount($tenantB));
    }

    public function test_fresh_schema_comparator_rejects_every_retired_name_and_includes_unknown_collections(): void
    {
        $database = $this->ownedDatabase(DB::connection('mongodb')->getMongoClient(), $this->fixturePrefix.'_retired-negative');
        foreach (self::RETIRED as $name) {
            $database->createCollection($name);
            try {
                $this->normalizedSchema($database, 'tenant', false);
                self::fail('Expected fresh retired collection rejection for '.$name);
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString($name, $exception->getMessage());
            } finally {
                $database->dropCollection($name);
            }
        }

        $before = $this->fingerprint($this->normalizedSchema($database, 'tenant', false));
        $database->createCollection('unclassified_migration_collection');
        self::assertNotSame($before, $this->fingerprint($this->normalizedSchema($database, 'tenant', false)));
    }

    private function assertDumpContract(): void
    {
        foreach (['landlord.migrations.json', 'tenant_boora.migrations.json', 'landlord.schema.json', 'tenant_boora.schema.json'] as $file) {
            self::assertFileExists(self::DUMPS.'/'.$file);
        }
    }

    private function assertSafeLocalTopology(): void
    {
        foreach (['mongodb', 'landlord', 'tenant'] as $connection) {
            $dsn = (string) config("database.connections.$connection.dsn", '');
            self::assertTrue($this->isSafeFixtureDsn($dsn, null), "$connection must be local mongodb:// before fixture mutation.");
            $this->baseDsns[$connection] = $dsn;
        }
    }

    private function isSafeFixtureDsn(string $dsn, ?string $expectedDatabase): bool
    {
        if (! str_starts_with(strtolower($dsn), 'mongodb://') || str_starts_with(strtolower($dsn), 'mongodb+srv://')) {
            return false;
        }
        $parts = parse_url($dsn);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $database = trim((string) ($parts['path'] ?? ''), '/');
        if (! in_array($host, ['mongo', 'localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        return $expectedDatabase === null || $database === $expectedDatabase;
    }

    private function configureOwnedConnections(): void
    {
        foreach (['mongodb' => 'default', 'landlord' => 'landlord', 'tenant' => 'tenant'] as $connection => $suffix) {
            $name = $this->fixturePrefix.'_'.$suffix;
            config(["database.connections.$connection.dsn" => $this->fixtureDsn($connection, $name), "database.connections.$connection.database" => $name]);
            DB::purge($connection);
            $this->owned[] = $name;
            $database = DB::connection($connection)->getDatabase();
            self::assertSame($name, $database->getDatabaseName());
            $this->assertEffectiveLocalServer($connection);
            self::assertSame(0, $database->selectCollection('__migration_objective_owner')->countDocuments());
            $database->selectCollection('__migration_objective_owner')->insertOne(['run' => $this->fixturePrefix, 'created_at' => new UTCDateTime]);
            self::assertTrue($this->isOwnedFixture($database, $name));
        }
    }

    /** @return array{0: object, 1: object} */
    private function newFixturePair(string $suffix): array
    {
        $client = DB::connection('mongodb')->getMongoClient();
        $landlord = $this->ownedDatabase($client, $this->fixturePrefix.'_landlord_'.$suffix);
        $tenant = $this->ownedDatabase($client, $this->fixturePrefix.'_tenant_'.$suffix);

        return [$landlord, $tenant];
    }

    private function ownedDatabase(object $client, string $name): object
    {
        $this->owned[] = $name;
        $database = $client->selectDatabase($name);
        $database->selectCollection('__migration_objective_owner')->insertOne(['run' => $this->fixturePrefix]);
        self::assertTrue($this->isOwnedFixture($database, $name));

        return $database;
    }

    private function createOutOfScopeSentinel(): object
    {
        $database = DB::connection('mongodb')->getMongoClient()->selectDatabase('migration_objective_out_of_scope_sentinel');
        $collection = $database->selectCollection('sentinels');
        $collection->deleteMany(['run' => $this->fixturePrefix]);
        $collection->insertOne(['run' => $this->fixturePrefix, 'value' => 'outside-objective-fixture']);

        return $collection;
    }

    private function isOwnedFixture(object $database, string $expected): bool
    {
        return str_starts_with($expected, $this->fixturePrefix.'_')
            && $database->getDatabaseName() === $expected
            && $database->selectCollection('__migration_objective_owner')->countDocuments(['run' => $this->fixturePrefix]) === 1;
    }

    private function migrateComplete(object $landlord, object $tenant): void
    {
        $this->withDatabases($landlord, $tenant, function (): void {
            $this->assertCompletePathInventory();
            self::assertSame(0, Artisan::call('migrate', [
                '--database' => 'landlord', '--path' => (array) config('multitenancy.landlord_migration_paths'), '--force' => true,
            ]), Artisan::output());
            $this->migrateTenant(DB::connection('tenant')->getDatabase());
        });
    }

    private function migrateTenant(object $tenant): void
    {
        $this->withDatabases(DB::connection('landlord')->getDatabase(), $tenant, function (): void {
            $tenantId = new ObjectId;
            DB::connection('landlord')->getDatabase()->selectCollection('tenants')->insertOne([
                '_id' => $tenantId,
                'name' => 'Objective '.$tenantId,
                'slug' => 'objective-'.$tenantId,
                'subdomain' => 'objective-'.$tenantId,
                'database' => (string) config('database.connections.tenant.database'),
            ]);
            try {
                $paths = implode(' ', array_map(static fn (string $path): string => '--path='.$path, (array) config('multitenancy.tenant_migration_paths')));
                self::assertSame(0, Artisan::call('tenants:artisan', [
                    'artisanCommand' => 'migrate --database=tenant '.$paths.' --force',
                ]), Artisan::output());
            } finally {
                Tenant::forgetCurrent();
                Context::forget((string) config('multitenancy.current_tenant_context_key', 'tenantId'));
                DB::connection('landlord')->getDatabase()->selectCollection('tenants')->deleteOne(['_id' => $tenantId]);
            }
            self::assertNull(Tenant::current());
        });
    }

    private function withDatabases(object $landlord, object $tenant, callable $callback): void
    {
        $landlordName = $landlord->getDatabaseName();
        $tenantName = $tenant->getDatabaseName();
        if ($landlordName === $tenantName || ! $this->isOwnedFixture($landlord, $landlordName) || ! $this->isOwnedFixture($tenant, $tenantName)) {
            throw new \RuntimeException('Objective replay requires owned, distinct databases before migration.');
        }
        config([
            'database.connections.landlord.dsn' => $this->fixtureDsn('landlord', $landlordName),
            'database.connections.landlord.database' => $landlordName,
            'database.connections.tenant.dsn' => $this->fixtureDsn('tenant', $tenantName),
            'database.connections.tenant.database' => $tenantName,
        ]);
        DB::purge('landlord');
        DB::purge('tenant');
        $callback();
    }

    private function assertCompletePathInventory(): void
    {
        $landlord = (array) config('multitenancy.landlord_migration_paths');
        if (! in_array('database/migrations/landlord', $landlord, true)
            || ! in_array('packages/belluga/belluga_settings/database/migrations_landlord', $landlord, true)) {
            throw new \RuntimeException('Objective replay requires both configured landlord migration paths, including Settings.');
        }
        if (count((array) config('multitenancy.tenant_migration_paths')) !== 7) {
            throw new \RuntimeException('Objective replay requires all seven configured tenant migration paths.');
        }
    }

    private function importLedger(object $database, string $file): void
    {
        $rows = json_decode((string) file_get_contents(self::DUMPS.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
        $database->selectCollection('migrations')->insertMany(array_map(static fn (array $row): array => [
            'migration' => $row['migration'], 'batch' => $row['batch'],
        ], $rows));
    }

    private function importSchema(object $database, string $file): void
    {
        $schema = json_decode((string) file_get_contents(self::DUMPS.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
        foreach ($schema['collections'] as $definition) {
            $name = $definition['name'];
            if ($name === '__migration_objective_owner') {
                continue;
            }
            $options = $this->extendedJson($definition['options'] ?? []);
            $database->createCollection($name, $options);
            foreach ($definition['indexes'] ?? [] as $index) {
                if (($index['name'] ?? '') === '_id_') {
                    continue;
                }
                $options = $this->extendedJson(array_diff_key($index, array_flip(['v', 'key', 'ns'])));
                $database->selectCollection($name)->createIndex($this->extendedJson($index['key']), $options);
            }
        }
    }

    private function extendedJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_keys($value) === ['$numberInt'] || array_keys($value) === ['$numberLong']) {
            return (int) reset($value);
        }
        if (array_keys($value) === ['$numberDouble']) {
            return (float) reset($value);
        }
        if (array_keys($value) === ['$oid']) {
            return new ObjectId((string) reset($value));
        }
        if (array_keys($value) === ['$date']) {
            $date = reset($value);

            return new UTCDateTime((int) (is_array($date) ? ($date['$numberLong'] ?? 0) : $date));
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->extendedJson($item);
        }

        return $value;
    }

    private function hasCollection(object $database, string $expected): bool
    {
        foreach ($database->listCollectionNames() as $name) {
            if ($name === $expected) {
                return true;
            }
        }

        return false;
    }

    private function ledgerCount(object $database): int
    {
        return $database->selectCollection('migrations')->countDocuments();
    }

    private function ledger(object $database): array
    {
        return array_map(
            static fn (object $row): array => json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            $database->selectCollection('migrations')->find([], ['projection' => ['_id' => 0], 'sort' => ['migration' => 1]])->toArray(),
        );
    }

    /** @return list<string> */
    private function indexNames(object $database, string $collection): array
    {
        $names = array_map(static fn (object $index): string => $index->getName(), iterator_to_array($database->selectCollection($collection)->listIndexes()));
        sort($names);

        return $names;
    }

    private function fingerprint(array $schema): string
    {
        return hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    private function normalizedSchema(object $database, string $scope, bool $allowRetired): array
    {
        $collections = [];
        foreach ($database->listCollections() as $collection) {
            $name = $collection->getName();
            if (in_array($name, ['__migration_objective_owner', 'objective_preexisting_sentinels'], true) || in_array($name, self::RUNTIME_EXCLUSIONS[$scope], true)) {
                continue;
            }
            if (in_array($name, self::RETIRED, true)) {
                if (! $allowRetired) {
                    throw new \RuntimeException('Fresh migration lineage created retired collection '.$name);
                }

                continue;
            }
            $collectionOptions = json_decode(json_encode($collection->getOptions(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $validator = $this->canonical($collectionOptions['validator'] ?? []);
            unset($collectionOptions['validator']);
            $indexes = [];
            foreach ($database->selectCollection($name)->listIndexes() as $index) {
                $indexes[] = $this->normalizedIndex($index);
            }
            usort($indexes, static fn (array $a, array $b): int => ($a['name'] ?? '') <=> ($b['name'] ?? ''));
            $collections[] = [
                'name' => $name,
                'type' => $collection->getType(),
                'options' => $this->canonical($collectionOptions),
                'validator' => $validator,
                'indexes' => $indexes,
            ];
        }
        usort($collections, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $collections;
    }

    /** @return array<string, array<string, mixed>> */
    private function retiredSchema(object $database): array
    {
        return $this->selectedSchema($database, self::RETIRED);
    }

    /** @param list<string> $names @return array<string, array<string, mixed>> */
    private function selectedSchema(object $database, array $names): array
    {
        $selected = [];
        foreach ($this->normalizedCollectionDefinitions($database) as $name => $definition) {
            if (in_array($name, $names, true)) {
                $selected[$name] = $definition;
            }
        }
        ksort($selected);

        return $selected;
    }

    /** @return array<string, array<string, mixed>> */
    private function normalizedCollectionDefinitions(object $database): array
    {
        $definitions = [];
        foreach ($database->listCollections() as $collection) {
            $name = $collection->getName();
            $indexes = [];
            foreach ($database->selectCollection($name)->listIndexes() as $index) {
                $indexes[] = $this->normalizedIndex($index);
            }
            usort($indexes, static fn (array $a, array $b): int => ($a['name'] ?? '') <=> ($b['name'] ?? ''));
            $definitions[$name] = ['options' => $this->canonical(json_decode(json_encode($collection->getOptions(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)), 'indexes' => $indexes];
        }
        ksort($definitions);

        return $definitions;
    }

    /** @return array{name:string,key:array<string,mixed>,options:array<string,mixed>} */
    private function normalizedIndex(object $index): array
    {
        $options = [];
        foreach (['v', 'unique', 'sparse', 'hidden', 'expireAfterSeconds', 'partialFilterExpression', 'collation', 'weights', 'default_language', 'language_override', 'textIndexVersion', '2dsphereIndexVersion', 'wildcardProjection'] as $option) {
            if (isset($index[$option])) {
                $options[$option] = $index[$option];
            }
        }

        return [
            'name' => $index->getName(),
            'key' => $this->normalizeOrderedKey(json_decode(json_encode($index->getKey(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)),
            'options' => $this->canonical(json_decode(json_encode($options, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)),
        ];
    }

    private function fixtureDsn(string $connection, string $database): string
    {
        $dsn = $this->baseDsns[$connection] ?? '';
        $rewritten = preg_replace('#^(.+?://[^/]+)(?:/[^?]*)?(\?.*)?$#', '$1/'.$database.'$2', $dsn);
        if (! is_string($rewritten) || ! $this->isSafeFixtureDsn($rewritten, $database)) {
            throw new \RuntimeException('Unable to build a safe local fixture DSN.');
        }

        return $rewritten;
    }

    private function assertEffectiveLocalServer(string $connection): void
    {
        $client = DB::connection($connection)->getMongoClient();
        iterator_to_array($client->selectDatabase('admin')->command(['ping' => 1]));
        $servers = $client->getManager()->getServers();
        self::assertNotEmpty($servers);
        foreach ($servers as $server) {
            self::assertContains(strtolower($server->getHost()), ['mongo', 'localhost', '127.0.0.1', '::1']);
        }
    }

    private function seedSentinel(object $database, string $value): void
    {
        $database->selectCollection('objective_preexisting_sentinels')->insertOne(['run' => $this->fixturePrefix, 'value' => $value]);
    }

    private function assertSentinel(object $database, string $value): void
    {
        self::assertSame(1, $database->selectCollection('objective_preexisting_sentinels')->countDocuments(['run' => $this->fixturePrefix, 'value' => $value]));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @param array<string, mixed> $key @return array<string, mixed> */
    private function normalizeOrderedKey(array $key): array
    {
        // MongoDB compound-key order is semantic; never call canonical() here.
        foreach ($key as $field => $direction) {
            $key[$field] = $this->canonicalScalar($direction);
        }

        return $key;
    }

    private function canonicalScalar(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_keys($value) === ['$numberInt'] || array_keys($value) === ['$numberLong']) {
            return (int) reset($value);
        }
        if (array_keys($value) === ['$numberDouble']) {
            return (float) reset($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalScalar($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array{0:int,1:int,2:int} */
    private function schemaCounts(array $schema, object $database, string $scope): array
    {
        return [count($schema), array_sum(array_map(static fn (array $collection): int => count($collection['indexes']), $schema)), count(array_filter($schema, static fn (array $collection): bool => $collection['validator'] !== []))];
    }
}
