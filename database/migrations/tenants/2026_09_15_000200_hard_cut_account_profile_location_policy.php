<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

return new class extends Migration
{
    private const BATCH_SIZE = 100;

    public function up(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $types = $database->selectCollection('account_profile_types');
        $profiles = $database->selectCollection('account_profiles');

        $this->assertIndexPlan($database);

        $this->eachTypeBatch($types, function (array $batch): void {
            foreach ($batch as $document) {
                $native = $this->native($document);
                $capabilities = $this->document($native['capabilities'] ?? []);
                if (! $this->isLegacyConfiguration($capabilities)) {
                    $this->assertTyped($capabilities);
                    $this->assertFinalLocationShape($capabilities);

                    continue;
                }
                $this->assertTyped($capabilities, ['is_poi_enabled', 'is_reference_location_enabled']);

                $this->typedBoolean($capabilities, 'is_queryable');
                $type = trim((string) ($native['type'] ?? ''));
                if ($type === '') {
                    throw new RuntimeException('tenant.account_profile_location_policy_v1 refuses a type without a key.');
                }
            }
        });

        // Every validation above completes before the first document or index write.
        $this->eachTypeBatch($types, function (array $batch) use ($profiles, $types): void {
            $operations = [];
            $expected = [];
            foreach ($batch as $document) {
                $observed = $document instanceof BSONDocument
                    ? $document->getArrayCopy()
                    : (is_array($document) ? $document : []);
                $native = $this->native($document);
                $capabilities = $this->document($native['capabilities'] ?? []);
                if (! $this->isLegacyConfiguration($capabilities)) {
                    continue;
                }

                $oldPoi = $this->typedBooleanOrFalse($capabilities, 'is_poi_enabled');
                $oldQueryable = $this->typedBoolean($capabilities, 'is_queryable');
                $oldReference = $this->typedBooleanOrFalse($capabilities, 'is_reference_location_enabled');
                $locationPolicy = 'disabled';
                if ($oldPoi) {
                    $locationPolicy = $profiles->countDocuments([
                        'profile_type' => trim((string) ($native['type'] ?? '')),
                        '$or' => $this->invalidLocationAlternatives(),
                    ]) > 0 ? 'optional' : 'required';
                }
                $capabilityRevision = $this->nonNegativeRevision($native['capability_revision'] ?? null);
                $hostFenceRevision = $this->nonNegativeRevision($native['host_admission_fence_revision'] ?? null);
                $filter = ['_id' => $native['_id']];
                $this->addObservedFieldToCasFilter($filter, 'capabilities', $observed);
                $this->addObservedFieldToCasFilter($filter, 'capability_revision', $observed);
                $this->addObservedFieldToCasFilter($filter, 'host_admission_fence_revision', $observed);
                $set = [
                    'capabilities.location_policy' => $this->configurationEnvelope($locationPolicy),
                    'capabilities.is_map_poi_enabled' => $this->configurationEnvelope($oldPoi),
                    'capabilities.is_physical_host_enabled' => $this->configurationEnvelope($oldPoi && $oldQueryable),
                    'capabilities.is_reference_location_enabled' => $this->configurationEnvelope($oldReference),
                    'capability_revision' => $capabilityRevision,
                    'host_admission_fence_revision' => $hostFenceRevision,
                ];
                $operations[] = ['updateOne' => [
                    $filter,
                    ['$set' => $set, '$unset' => ['capabilities.is_poi_enabled' => '']],
                ]];
                unset($capabilities['is_poi_enabled']);
                $capabilities['location_policy'] = $this->native($set['capabilities.location_policy']);
                $capabilities['is_map_poi_enabled'] = $this->native($set['capabilities.is_map_poi_enabled']);
                $capabilities['is_physical_host_enabled'] = $this->native($set['capabilities.is_physical_host_enabled']);
                $capabilities['is_reference_location_enabled'] = $this->native($set['capabilities.is_reference_location_enabled']);
                $expected[(string) $native['_id']] = [
                    '_id' => $native['_id'],
                    'capabilities' => $capabilities,
                    'capability_revision' => $capabilityRevision,
                    'host_admission_fence_revision' => $hostFenceRevision,
                ];
            }

            if ($operations === []) {
                return;
            }
            $result = $types->bulkWrite($operations, ['ordered' => true]);
            if ($result->getMatchedCount() === count($operations)) {
                return;
            }
            foreach ($expected as $row) {
                $fresh = $this->native($types->findOne(['_id' => $row['_id']]));
                if (! is_array($fresh)) {
                    throw new RuntimeException('tenant.account_profile_location_policy_v1 detected a concurrent type removal.');
                }
                if ($this->document($fresh['capabilities'] ?? []) !== $row['capabilities']
                    || $this->nonNegativeRevision($fresh['capability_revision'] ?? null) !== $row['capability_revision']
                    || $this->nonNegativeRevision($fresh['host_admission_fence_revision'] ?? null) !== $row['host_admission_fence_revision']) {
                    throw new RuntimeException('tenant.account_profile_location_policy_v1 detected a concurrent capability mutation.');
                }
            }
        });

        $this->convergeIndexes($database);
    }

    public function down(): void {}

    /** @param callable(array<int, mixed>):void $consume */
    private function eachTypeBatch(MongoDB\Collection $types, callable $consume): void
    {
        $afterId = null;
        do {
            $filter = $afterId === null ? [] : ['_id' => ['$gt' => $afterId]];
            $batch = iterator_to_array($types->find($filter, [
                'sort' => ['_id' => 1],
                'limit' => self::BATCH_SIZE,
            ]), false);
            if ($batch === []) {
                return;
            }
            $consume($batch);
            $last = $this->native($batch[array_key_last($batch)]);
            $afterId = $last['_id'];
        } while (count($batch) === self::BATCH_SIZE);
    }

    /** @param array<string, mixed> $filter @param array<string, mixed> $source */
    private function addObservedFieldToCasFilter(array &$filter, string $field, array $source): void
    {
        $filter[$field] = array_key_exists($field, $source)
            ? $source[$field]
            : ['$exists' => false];
    }

    private function nonNegativeRevision(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  list<string>  $failClosedLegacyKeys
     */
    private function assertTyped(array $capabilities, array $failClosedLegacyKeys = []): void
    {
        foreach ($capabilities as $key => $raw) {
            if (in_array($key, $failClosedLegacyKeys, true)) {
                continue;
            }
            $envelope = $this->document($raw);
            $parameters = $this->native($envelope['parameters'] ?? null);
            if (! array_key_exists('value', $envelope)
                || ! is_array($parameters)
                || (array_is_list($parameters) && $parameters !== [])) {
                throw new RuntimeException("tenant.account_profile_location_policy_v1 refuses malformed capability [{$key}].");
            }
        }
    }

    /** @param array<string, mixed> $capabilities */
    private function isLegacyConfiguration(array $capabilities): bool
    {
        if (array_key_exists('is_poi_enabled', $capabilities)) {
            return true;
        }

        foreach (['location_policy', 'is_map_poi_enabled', 'is_physical_host_enabled', 'is_reference_location_enabled'] as $key) {
            if (! array_key_exists($key, $capabilities)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $capabilities */
    private function assertFinalLocationShape(array $capabilities): void
    {
        $policy = $this->document($capabilities['location_policy'] ?? [])['value'] ?? null;
        if (! in_array($policy, ['disabled', 'optional', 'required'], true)) {
            throw new RuntimeException('tenant.account_profile_location_policy_v1 refuses a malformed final location policy.');
        }
        foreach (['is_map_poi_enabled', 'is_physical_host_enabled', 'is_reference_location_enabled'] as $key) {
            if (! is_bool($this->document($capabilities[$key] ?? [])['value'] ?? null)) {
                throw new RuntimeException("tenant.account_profile_location_policy_v1 refuses malformed final capability [{$key}].");
            }
        }
    }

    /** @param array<string, mixed> $capabilities */
    private function typedBoolean(array $capabilities, string $key): bool
    {
        $value = $this->document($capabilities[$key] ?? [])['value'] ?? null;
        if (! is_bool($value)) {
            throw new RuntimeException("tenant.account_profile_location_policy_v1 refuses malformed Boolean capability [{$key}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $capabilities */
    private function typedBooleanOrFalse(array $capabilities, string $key): bool
    {
        $envelope = $this->document($capabilities[$key] ?? []);
        $value = $envelope['value'] ?? null;
        $parameters = $envelope['parameters'] ?? null;

        return is_bool($value) && $parameters === []
            ? $value
            : false;
    }

    private function convergeIndexes(MongoDB\Database $database): void
    {
        $types = $database->selectCollection('account_profile_types');
        foreach ($types->listIndexes() as $index) {
            $name = $index->getName();
            $keys = $this->native($index->getKey());
            if ($name !== '_id_' && $name !== 'type_1'
                && array_filter(array_keys($keys), static fn (string $key): bool => str_contains($key, 'is_poi_enabled')) !== []) {
                $types->dropIndex($name);
            }
        }
        foreach ([
            'idx_account_profile_types_map_poi_location_policy_v1' => ['capabilities.is_map_poi_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1],
            'idx_account_profile_types_physical_host_location_policy_v1' => ['capabilities.is_physical_host_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1],
            'idx_account_profile_types_reference_location_policy_v1' => ['capabilities.is_reference_location_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1],
            'idx_account_profile_types_location_policy_v1' => ['capabilities.location_policy.value' => 1, 'type' => 1],
        ] as $name => $keys) {
            $types->createIndex($keys, ['name' => $name]);
        }

        $database->selectCollection('account_profiles')->createIndex(
            ['profile_type' => 1, 'location.type' => 1],
            ['name' => 'idx_account_profiles_profile_type_location_type_v1'],
        );
        $database->selectCollection('events')->createIndex(
            ['place_ref.type' => 1, 'place_ref.id' => 1],
            ['name' => 'idx_events_place_ref_type_id_v1'],
        );
        $database->selectCollection('event_occurrences')->createIndex(
            ['place_ref.type' => 1, 'place_ref.id' => 1],
            ['name' => 'idx_event_occurrences_place_ref_type_id_v1'],
        );
        $database->selectCollection('event_occurrences')->createIndex(
            ['programming_items.place_ref.type' => 1, 'programming_items.place_ref.id' => 1],
            ['name' => 'idx_event_occurrences_programming_place_ref_type_id_v1'],
        );
    }

    private function assertIndexPlan(MongoDB\Database $database): void
    {
        $plans = [
            'account_profile_types' => [
                'idx_account_profile_types_map_poi_location_policy_v1' => ['capabilities.is_map_poi_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1],
                'idx_account_profile_types_physical_host_location_policy_v1' => ['capabilities.is_physical_host_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1],
                'idx_account_profile_types_reference_location_policy_v1' => ['capabilities.is_reference_location_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1],
                'idx_account_profile_types_location_policy_v1' => ['capabilities.location_policy.value' => 1, 'type' => 1],
            ],
            'account_profiles' => [
                'idx_account_profiles_profile_type_location_type_v1' => ['profile_type' => 1, 'location.type' => 1],
            ],
            'events' => [
                'idx_events_place_ref_type_id_v1' => ['place_ref.type' => 1, 'place_ref.id' => 1],
            ],
            'event_occurrences' => [
                'idx_event_occurrences_place_ref_type_id_v1' => ['place_ref.type' => 1, 'place_ref.id' => 1],
                'idx_event_occurrences_programming_place_ref_type_id_v1' => ['programming_items.place_ref.type' => 1, 'programming_items.place_ref.id' => 1],
            ],
        ];

        foreach ($plans as $collectionName => $plan) {
            foreach ($database->selectCollection($collectionName)->listIndexes() as $index) {
                $name = $index->getName();
                $keys = $this->native($index->getKey());
                if (isset($plan[$name])) {
                    if ($keys !== $plan[$name] || $index->isUnique()) {
                        throw new RuntimeException("tenant.account_profile_location_policy_v1 refuses conflicting index [{$name}].");
                    }

                    continue;
                }
                foreach ($plan as $targetName => $targetKeys) {
                    if ($keys === $targetKeys) {
                        throw new RuntimeException("tenant.account_profile_location_policy_v1 refuses equivalent index [{$name}] for [{$targetName}].");
                    }
                }
            }
        }
    }

    private function configurationEnvelope(bool|string $value): BSONDocument
    {
        return new BSONDocument([
            'value' => $value,
            'parameters' => new BSONDocument([]),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function invalidLocationAlternatives(): array
    {
        return [
            ['location.type' => ['$ne' => 'Point']],
            ['$expr' => ['$ne' => [[
                '$size' => ['$cond' => [
                    ['$isArray' => '$location.coordinates'],
                    '$location.coordinates',
                    [],
                ]],
            ], 2]]],
            ['location.coordinates.0' => ['$not' => ['$type' => 'number']]],
            ['location.coordinates.1' => ['$not' => ['$type' => 'number']]],
            ['location.coordinates.0' => ['$lt' => -180]],
            ['location.coordinates.0' => ['$gt' => 180]],
            ['location.coordinates.1' => ['$lt' => -90]],
            ['location.coordinates.1' => ['$gt' => 90]],
        ];
    }

    /** @return array<string, mixed> */
    private function document(mixed $value): array
    {
        $native = $this->native($value);

        return is_array($native) && ! array_is_list($native) ? $native : [];
    }

    private function native(mixed $value): mixed
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->native($item);
            }
        }

        return $value;
    }
};
