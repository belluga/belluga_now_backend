<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\AccountProfiles\AccountProfileTypeCapabilityRepairer;
use App\Application\AccountProfiles\AccountProfileTypeIndexManifest;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

class AccountProfileTypeCapabilityMigrationTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
        $this->profileTypesCollection()->deleteMany([]);
    }

    public function test_migration_completes_malformed_capabilities_without_rewriting_explicit_booleans(): void
    {
        $collection = $this->profileTypesCollection();
        $collection->insertMany([
            [
                'type' => 'personal',
                'capabilities' => [
                    'is_favoritable' => false,
                    'has_bio' => 'invalid',
                ],
            ],
            [
                'type' => 'custom',
                'capabilities' => [
                    'is_queryable' => false,
                    'is_favoritable' => null,
                    'is_reference_location_enabled' => true,
                ],
            ],
        ]);

        $this->runCapabilityCanonicalizationMigration();

        $catalog = new AccountProfileTypeCapabilityCatalog;
        $personal = $this->capabilitiesFor('personal');
        $custom = $this->capabilitiesFor('custom');

        $this->assertCompleteBooleanMap($catalog, $personal);
        $this->assertCompleteBooleanMap($catalog, $custom);
        $this->assertFalse($personal['is_favoritable']);
        $this->assertFalse($personal['has_bio']);
        $this->assertFalse($personal['is_queryable']);
        $this->assertTrue($personal['is_inviteable']);
        $this->assertFalse($custom['is_queryable']);
        $this->assertFalse($custom['is_favoritable']);
        $this->assertTrue($custom['is_reference_location_enabled']);
        $this->assertFalse($custom['is_poi_enabled']);

        $this->runCapabilityCanonicalizationMigration();

        $this->assertSame($personal, $this->capabilitiesFor('personal'));
        $this->assertSame($custom, $this->capabilitiesFor('custom'));
    }

    public function test_repair_filter_preserves_an_explicit_boolean_written_after_candidate_selection(): void
    {
        $collection = $this->profileTypesCollection();
        $insert = $collection->insertOne([
            'type' => 'custom',
            'capabilities' => [
                'is_favoritable' => null,
            ],
        ]);
        $documentId = $insert->getInsertedId();

        $repairer = new AccountProfileTypeCapabilityRepairer(new AccountProfileTypeCapabilityCatalog);
        $collection->updateOne(
            ['_id' => $documentId],
            ['$set' => ['capabilities.is_favoritable' => false]],
        );

        $result = $collection->updateOne(
            array_merge(
                ['_id' => $documentId],
                $repairer->repairableFieldFilter('is_favoritable'),
            ),
            ['$set' => ['capabilities.is_favoritable' => true]],
        );

        $this->assertSame(0, $result->getMatchedCount());
        $this->assertFalse($this->capabilitiesFor('custom')['is_favoritable']);
    }

    public function test_literal_migration_repair_path_preserves_an_explicit_boolean_before_its_atomic_update(): void
    {
        $collection = $this->profileTypesCollection();
        $insert = $collection->insertOne([
            'type' => 'personal',
            'capabilities' => [
                'is_queryable' => null,
            ],
        ]);

        $collection->updateOne(
            ['_id' => $insert->getInsertedId()],
            ['$set' => ['capabilities.is_queryable' => true]],
        );

        $migration = $this->capabilityCanonicalizationMigration();
        $repairFields = new \ReflectionMethod($migration, 'repairFields');
        $repairFields->invoke(
            $migration,
            $collection,
            ['type' => 'personal'],
            ['is_queryable' => false],
            new UTCDateTime((int) now()->getTimestampMs()),
        );

        $this->assertTrue($this->capabilitiesFor('personal')['is_queryable']);
    }

    public function test_literal_migration_defaults_match_the_catalog_for_every_type_default_group(): void
    {
        $catalog = new AccountProfileTypeCapabilityCatalog;
        $types = ['custom', 'personal', 'artist', 'venue'];

        $this->profileTypesCollection()->insertMany(array_map(
            static fn (string $type): array => ['type' => $type],
            $types,
        ));

        $this->runCapabilityCanonicalizationMigration();

        foreach ($types as $type) {
            $this->assertSame(
                $catalog->completeForPersistence($type),
                $this->capabilitiesFor($type),
                "Literal migration defaults drifted for [{$type}].",
            );
        }
    }

    public function test_literal_migration_repairs_with_the_same_bounded_update_command_count_for_one_and_many_documents(): void
    {
        $oneDocumentCommands = $this->capabilityRepairUpdateCommands(1);
        $manyDocumentCommands = $this->capabilityRepairUpdateCommands(80);
        $expectedCapabilities = array_map(
            static fn (array $definition): string => "capabilities.{$definition['key']}",
            (new AccountProfileTypeCapabilityCatalog)->definitions(),
        );
        sort($expectedCapabilities);
        $expectedCommandCount = 4 * count($expectedCapabilities);

        $this->assertCount($expectedCommandCount, $oneDocumentCommands);
        $this->assertCount($expectedCommandCount, $manyDocumentCommands);
        $this->assertSame(
            $this->repairCommandShapeByTypeGroup($oneDocumentCommands),
            $this->repairCommandShapeByTypeGroup($manyDocumentCommands),
        );
        $this->assertSame([
            'artist' => $expectedCapabilities,
            'generic' => $expectedCapabilities,
            'personal' => $expectedCapabilities,
            'venue' => $expectedCapabilities,
        ], $this->repairCommandShapeByTypeGroup($oneDocumentCommands));
    }

    public function test_historical_capability_and_candidate_migrations_provision_every_declared_index(): void
    {
        $this->dropProfileTypeIndexesExceptPrimaryKey();
        $this->runCapabilityCanonicalizationMigration();
        $this->runCandidateDiscoveryMigration();

        foreach ((new AccountProfileTypeIndexManifest)->definitions() as $definition) {
            $index = $this->profileTypeIndexesByName()[$definition['name']] ?? null;

            $this->assertNotNull($index, "Missing manifest index [{$definition['name']}].");
            $this->assertSame($definition['keys'], $this->arrayFrom($index['key'] ?? []));
            $this->assertArrayNotHasKey('partialFilterExpression', $index);
        }
    }

    public function test_literal_migration_emits_explicit_simple_collation_for_every_fresh_history_index(): void
    {
        $this->dropProfileTypeIndexesExceptPrimaryKey();
        $trace = $this->captureTenantMongoCommands(function (): void {
            $this->runCapabilityCanonicalizationMigration();
        });
        $createIndexesCommands = $trace->commandsForCollection('createIndexes', 'account_profile_types');

        $this->assertCount(count((new AccountProfileTypeIndexManifest)->definitions()), $createIndexesCommands);

        foreach ($createIndexesCommands as $command) {
            $indexes = $this->arrayFrom($command['indexes'] ?? []);
            $this->assertCount(1, $indexes);

            $index = $this->arrayFrom($indexes[0]);
            $this->assertArrayHasKey('name', $index);
            $this->assertArrayHasKey('collation', $index);
            $this->assertSame(['locale' => 'simple'], $this->arrayFrom($index['collation']));
        }
    }

    public function test_base_migration_can_reapply_when_canonical_capability_indexes_already_exist(): void
    {
        $this->profileTypesCollection()->drop();

        $baseMigration = require base_path(
            'database/migrations/tenants/2026_01_29_000300_create_profile_types_collection.php',
        );
        $baseMigration->up();
        $this->runCapabilityCanonicalizationMigration();

        $baseMigration->up();

        $indexNames = array_map(
            static fn ($index): string => $index->getName(),
            iterator_to_array($this->profileTypesCollection()->listIndexes()),
        );

        $this->assertContains('idx_account_profile_types_candidate_queryable_v1', $indexNames);
        $this->assertContains('idx_account_profile_types_public_discovery_v1', $indexNames);
    }

    public function test_historical_capability_migration_can_reapply_after_candidate_name_transition(): void
    {
        $this->dropProfileTypeIndexesExceptPrimaryKey();
        $this->runCapabilityCanonicalizationMigration();
        $this->runCandidateDiscoveryMigration();

        $this->runCapabilityCanonicalizationMigration();

        $indexNames = array_map(
            static fn ($index): string => $index->getName(),
            iterator_to_array($this->profileTypesCollection()->listIndexes()),
        );

        $this->assertContains('idx_account_profile_types_candidate_queryable_v1', $indexNames);
        $this->assertContains('idx_account_profile_types_candidate_contact_capable_v1', $indexNames);
        $this->assertNotContains('idx_account_profile_types_queryable_candidates_v1', $indexNames);
        $this->assertNotContains('idx_account_profile_types_contact_channels_v1', $indexNames);
    }

    public function test_migration_repairs_only_the_current_tenant_collection(): void
    {
        $primary = Tenant::query()->firstOrFail();
        $primaryCollection = $this->profileTypesCollectionForTenant($primary);
        $primaryCollection->insertOne([
            'type' => 'primary-isolation-type',
            'capabilities' => [
                'is_favoritable' => null,
            ],
        ]);

        $secondary = Tenant::create([
            'name' => 'Capability Isolation Secondary',
            'subdomain' => 'capability-isolation-secondary',
        ]);

        try {
            $secondaryCollection = $this->profileTypesCollectionForTenant($secondary);
            $secondaryCollection->insertOne([
                'type' => 'secondary-isolation-type',
                'capabilities' => [
                    'is_favoritable' => null,
                ],
            ]);

            $this->runCapabilityCanonicalizationMigrationForDatabase((string) $primary->database);

            $this->assertFalse(
                $this->capabilitiesForTypeInCollection($primaryCollection, 'primary-isolation-type')['is_favoritable'],
            );
            $this->assertNull(
                $this->capabilitiesForTypeInCollection($secondaryCollection, 'secondary-isolation-type')['is_favoritable'],
            );
        } finally {
            $primary->makeCurrent();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function capabilityRepairUpdateCommands(int $documentCount): array
    {
        $collection = $this->profileTypesCollection();
        $collection->deleteMany([]);
        $collection->insertMany(array_map(
            static fn (int $number): array => ['type' => "custom-{$number}"],
            range(1, $documentCount),
        ));

        $trace = $this->captureTenantMongoCommands(function (): void {
            $this->runCapabilityCanonicalizationMigration();
        });

        return $trace->commandsForCollection('update', 'account_profile_types');
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return array<string, list<string>>
     */
    private function repairCommandShapeByTypeGroup(array $commands): array
    {
        $fieldsByGroup = [
            'artist' => [],
            'generic' => [],
            'personal' => [],
            'venue' => [],
        ];

        foreach ($commands as $command) {
            $updates = $this->arrayFrom($command['updates'] ?? []);
            $this->assertCount(1, $updates);

            $update = $this->arrayFrom($updates[0]);
            $filter = $this->arrayFrom($update['q'] ?? []);
            $this->assertArrayHasKey('type', $filter);
            $this->assertTrue((bool) ($update['multi'] ?? false));

            $capabilityFields = $this->repairCapabilityFields($filter);
            $this->assertCount(1, $capabilityFields);
            $this->assertSame(
                $capabilityFields,
                $this->repairCapabilityFieldsFromMutation($update),
            );

            $fieldsByGroup[$this->repairTypeGroupFor($filter['type'])][] = $capabilityFields[0];
        }

        foreach ($fieldsByGroup as &$fields) {
            sort($fields);
        }
        unset($fields);

        return $fieldsByGroup;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return list<string>
     */
    private function repairCapabilityFields(array $filter): array
    {
        $directFields = array_values(array_filter(
            array_keys($filter),
            static fn (string $field): bool => str_starts_with($field, 'capabilities.'),
        ));

        if ($directFields !== []) {
            return $directFields;
        }

        $fields = [];
        foreach ($this->arrayFrom($filter['$or'] ?? []) as $branch) {
            foreach (array_keys($this->arrayFrom($branch)) as $field) {
                if (str_starts_with($field, 'capabilities.')) {
                    $fields[] = $field;
                }
            }
        }

        sort($fields);

        return array_values(array_unique($fields));
    }

    /**
     * @param  array<string, mixed>  $update
     * @return list<string>
     */
    private function repairCapabilityFieldsFromMutation(array $update): array
    {
        $set = $this->arrayFrom($this->arrayFrom($update['u'] ?? [])['$set'] ?? []);
        $fields = array_values(array_filter(
            array_keys($set),
            static fn (string $field): bool => str_starts_with($field, 'capabilities.'),
        ));
        sort($fields);

        return $fields;
    }

    private function repairTypeGroupFor(mixed $typeFilter): string
    {
        if (in_array($typeFilter, ['artist', 'personal', 'venue'], true)) {
            return $typeFilter;
        }

        $typeFilter = $this->arrayFrom($typeFilter);
        $this->assertSame(['personal', 'artist', 'venue'], $this->arrayFrom($typeFilter['$nin'] ?? []));

        return 'generic';
    }

    /**
     * @param  callable(): void  $operation
     */
    private function captureTenantMongoCommands(callable $operation): AccountProfileTypeMigrationCommandTrace
    {
        $client = DB::connection('tenant')->getClient();
        $this->assertNotNull($client, 'The tenant Mongo client is required to trace migration commands.');

        $trace = new AccountProfileTypeMigrationCommandTrace;
        $client->addSubscriber($trace);

        try {
            $operation();
        } finally {
            $client->removeSubscriber($trace);
        }

        return $trace;
    }

    private function runCapabilityCanonicalizationMigration(): void
    {
        $this->capabilityCanonicalizationMigration()->up();
    }

    private function runCapabilityCanonicalizationMigrationForDatabase(string $databaseName): void
    {
        $originalDatabase = config('database.connections.tenant.database');

        config(['database.connections.tenant.database' => $databaseName]);
        DB::purge('tenant');

        try {
            $this->runCapabilityCanonicalizationMigration();
        } finally {
            config(['database.connections.tenant.database' => $originalDatabase]);
            DB::purge('tenant');
        }
    }

    private function capabilityCanonicalizationMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require base_path(
            'database/migrations/tenants/2026_07_18_000100_canonicalize_profile_type_capabilities.php',
        );

        return $migration;
    }

    private function dropProfileTypeIndexesExceptPrimaryKey(): void
    {
        $collection = $this->profileTypesCollection();

        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() !== '_id_') {
                $collection->dropIndex($index->getName());
            }
        }
    }

    private function runCandidateDiscoveryMigration(): void
    {
        $migration = require base_path(
            'database/migrations/tenants/2026_07_19_000100_add_account_profile_candidate_discovery_indexes.php',
        );

        $migration->up();
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $service->initialize(new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test'],
        ));
    }

    /**
     * @return \MongoDB\Collection<array<string, mixed>>
     */
    private function profileTypesCollection(): \MongoDB\Collection
    {
        return DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');
    }

    /**
     * @return \MongoDB\Collection<array<string, mixed>>
     */
    private function profileTypesCollectionForTenant(Tenant $tenant): \MongoDB\Collection
    {
        return DB::connection('tenant')
            ->getMongoClient()
            ->selectDatabase((string) $tenant->database)
            ->selectCollection('account_profile_types');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function profileTypeIndexesByName(): array
    {
        $indexes = [];
        foreach (DB::connection('tenant')->getDatabase()->command(['listIndexes' => 'account_profile_types']) as $index) {
            $row = $this->arrayFrom($index);
            $name = (string) ($row['name'] ?? '');
            if ($name !== '') {
                $indexes[$name] = $row;
            }
        }

        return $indexes;
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilitiesFor(string $type): array
    {
        return $this->capabilitiesForTypeInCollection($this->profileTypesCollection(), $type);
    }

    /**
     * @param  \MongoDB\Collection<array<string, mixed>>  $collection
     * @return array<string, mixed>
     */
    private function capabilitiesForTypeInCollection(\MongoDB\Collection $collection, string $type): array
    {
        $document = $collection->findOne(['type' => $type]);
        $this->assertNotNull($document);

        return $this->arrayFrom($document['capabilities'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function assertCompleteBooleanMap(
        AccountProfileTypeCapabilityCatalog $catalog,
        array $capabilities,
    ): void {
        foreach ($catalog->definitions() as $definition) {
            $key = $definition['key'];
            $this->assertArrayHasKey($key, $capabilities);
            $this->assertIsBool($capabilities[$key]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayFrom(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }

        if (is_object($value)) {
            return get_object_vars($value);
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return is_array($value) ? $value : [];
    }

    private function readSource(string $relativePath): string
    {
        $source = file_get_contents(base_path($relativePath));
        $this->assertIsString($source, "Failed to read [{$relativePath}].");

        return $source;
    }
}

final class AccountProfileTypeMigrationCommandTrace implements CommandSubscriber
{
    /** @var list<array{name:string, command:array<string, mixed>}> */
    private array $commands = [];

    public function commandStarted(CommandStartedEvent $event): void
    {
        $this->commands[] = [
            'name' => $event->getCommandName(),
            'command' => get_object_vars($event->getCommand()),
        ];
    }

    public function commandSucceeded(CommandSucceededEvent $event): void {}

    public function commandFailed(CommandFailedEvent $event): void {}

    /**
     * @return list<array<string, mixed>>
     */
    public function commandsForCollection(string $commandName, string $collection): array
    {
        $commands = [];

        foreach ($this->commands as $entry) {
            if ($entry['name'] !== $commandName) {
                continue;
            }

            if (($entry['command'][$commandName] ?? null) !== $collection) {
                continue;
            }

            $commands[] = $entry['command'];
        }

        return $commands;
    }
}
