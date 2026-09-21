<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

return new class extends Migration
{
    private const BATCH_SIZE = 100;

    private const LEGACY_KEYS = [
        'is_queryable', 'is_publicly_navigable', 'is_publicly_discoverable',
        'is_favoritable', 'is_inviteable', 'is_poi_enabled',
        'is_reference_location_enabled', 'has_bio', 'has_taxonomies',
        'has_avatar', 'has_cover', 'has_events', 'has_gallery',
        'has_nested_profile_groups', 'has_contact_channels', 'has_external_links',
        'has_content',
    ];

    private const FINAL_LOCATION_KEYS = [
        'location_policy', 'is_map_poi_enabled', 'is_physical_host_enabled',
    ];

    public function up(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $types = $database->selectCollection('account_profile_types');
        $definitions = $database->selectCollection('account_profile_capability_definitions');

        $this->assertIndexPlan($types, array_map(
            static fn (array $keys): array => ['keys' => $keys, 'unique' => false],
            $this->typeIndexes(),
        ), allowEquivalentDifferentName: true);
        $this->assertIndexPlan($definitions, [
            'uq_account_profile_capability_definitions_key_v1' => [
                'keys' => ['key' => 1],
                'unique' => true,
            ],
        ]);
        $duplicateDefinition = $definitions->aggregate([
            ['$group' => ['_id' => '$key', 'count' => ['$sum' => 1]]],
            ['$match' => ['count' => ['$gt' => 1]]],
            ['$limit' => 1],
        ])->toArray()[0] ?? null;
        if ($duplicateDefinition !== null) {
            throw new RuntimeException('tenant.account_profile_capabilities_v1 refuses duplicate persisted capability definitions.');
        }

        $this->eachTypeBatch($types, function (array $batch): void {
            foreach ($batch as $document) {
                $this->validatedConfiguration($document);
            }
        });

        // The complete preflight above finishes before the first document or
        // index write. Each following batch is independently rerunnable and
        // compare-and-swaps the exact capability/revision state it observed.
        $this->eachTypeBatch($types, function (array $batch) use ($types): void {
            $operations = [];
            $expected = [];
            foreach ($batch as $document) {
                $observed = $document instanceof BSONDocument
                    ? $document->getArrayCopy()
                    : (is_array($document) ? $document : []);
                $native = $this->native($document);
                $capabilities = $this->validatedConfiguration($document);
                $capabilityRevision = $this->nonNegativeRevision($native['capability_revision'] ?? null);
                $hostFenceRevision = $this->nonNegativeRevision($native['host_admission_fence_revision'] ?? null);
                $filter = ['_id' => $native['_id']];
                $this->addObservedFieldToCasFilter($filter, 'capabilities', $observed);
                $this->addObservedFieldToCasFilter($filter, 'capability_revision', $observed);
                $this->addObservedFieldToCasFilter($filter, 'host_admission_fence_revision', $observed);
                $persisted = $this->configurationForPersistence($capabilities);
                $operations[] = ['updateOne' => [
                    $filter,
                    ['$set' => [
                        'capabilities' => $persisted,
                        'capability_revision' => $capabilityRevision,
                        'host_admission_fence_revision' => $hostFenceRevision,
                    ]],
                ]];
                $expected[(string) $native['_id']] = [
                    '_id' => $native['_id'],
                    'capabilities' => $this->native($persisted),
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
                    throw new RuntimeException('tenant.account_profile_capabilities_v1 detected a concurrent type removal.');
                }
                if ($this->document($fresh['capabilities'] ?? []) !== $row['capabilities']
                    || $this->nonNegativeRevision($fresh['capability_revision'] ?? null) !== $row['capability_revision']
                    || $this->nonNegativeRevision($fresh['host_admission_fence_revision'] ?? null) !== $row['host_admission_fence_revision']) {
                    throw new RuntimeException('tenant.account_profile_capabilities_v1 detected a concurrent capability mutation.');
                }
            }
        });

        $catalog = $this->definitions();
        foreach ($catalog as $definition) {
            $definitions->replaceOne(
                ['key' => $definition['key']],
                $this->definitionForPersistence($definition),
                ['upsert' => true],
            );
        }
        $definitions->deleteMany(['key' => ['$nin' => array_column($catalog, 'key')]]);
        $definitions->createIndex(['key' => 1], ['name' => 'uq_account_profile_capability_definitions_key_v1', 'unique' => true]);

        $this->convergeTypeIndexes($types);
    }

    public function down(): void {}

    /** @return array<string, array{value:mixed,parameters:array<string,int>}> */
    private function validatedConfiguration(mixed $document): array
    {
        $native = $this->native($document);
        $capabilities = $this->document($native['capabilities'] ?? []);
        $shape = $this->shape($capabilities);
        if ($shape === 'mixed') {
            throw new RuntimeException('tenant.account_profile_capabilities_v1 refuses mixed flat/typed capability data.');
        }
        if ($shape === 'flat') {
            $unknown = array_diff(array_keys($capabilities), self::LEGACY_KEYS);
            if ($unknown !== []) {
                throw new RuntimeException('tenant.account_profile_capabilities_v1 refuses unknown capability keys.');
            }
            foreach ($capabilities as $key => $value) {
                if (! is_bool($value)) {
                    throw new RuntimeException("tenant.account_profile_capabilities_v1 refuses malformed capability [{$key}].");
                }
            }

            return $this->fromFlat($capabilities);
        }
        $this->assertTypedConfiguration($capabilities);

        return $capabilities;
    }

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

    /** @param array<string, mixed> $flat @return array<string, array{value:mixed,parameters:array<string, int>}> */
    private function fromFlat(array $flat): array
    {
        $configuration = [];
        foreach ($this->definitions() as $definition) {
            $key = $definition['key'];
            if (in_array($key, ['location_policy', 'is_map_poi_enabled', 'is_physical_host_enabled'], true)) {
                continue;
            }
            $value = array_key_exists($key, $flat) ? $flat[$key] : $definition['default_value'];
            $parameters = [];
            foreach ($definition['parameters'] as $parameter) {
                $parameters[$parameter['key']] = $parameter['default_value'];
            }
            $configuration[$key] = ['value' => $value, 'parameters' => $parameters];
        }

        $configuration['is_poi_enabled'] = [
            'value' => ($flat['is_poi_enabled'] ?? false) === true,
            'parameters' => [],
        ];

        ksort($configuration, SORT_STRING);

        return $configuration;
    }

    /** @param array<string, mixed> $capabilities */
    private function shape(array $capabilities): string
    {
        if ($capabilities === []) {
            return 'flat';
        }
        $documents = 0;
        foreach ($capabilities as $value) {
            if (is_array($value) || $value instanceof BSONDocument) {
                $documents++;
            }
        }

        return $documents === 0 ? 'flat' : ($documents === count($capabilities) ? 'typed' : 'mixed');
    }

    /** @param array<string, mixed> $capabilities */
    private function assertTypedConfiguration(array $capabilities): void
    {
        $actualKeys = array_keys($capabilities);
        sort($actualKeys, SORT_STRING);
        $transitionKeys = array_values(array_diff(
            array_column($this->definitions(), 'key'),
            self::FINAL_LOCATION_KEYS,
        ));
        $transitionKeys[] = 'is_poi_enabled';
        sort($transitionKeys, SORT_STRING);
        $finalKeys = array_column($this->definitions(), 'key');
        sort($finalKeys, SORT_STRING);
        if ($actualKeys !== $transitionKeys && $actualKeys !== $finalKeys) {
            throw new RuntimeException('tenant.account_profile_capabilities_v1 refuses partial or unknown typed capability data.');
        }

        $definitions = [];
        foreach ($this->definitions() as $definition) {
            $definitions[$definition['key']] = $definition;
        }
        $definitions['is_poi_enabled'] = $this->boolean('is_poi_enabled', 'location');

        foreach ($capabilities as $key => $raw) {
            $envelope = $this->document($raw);
            $envelopeKeys = array_keys($envelope);
            sort($envelopeKeys, SORT_STRING);
            if (! array_key_exists('value', $envelope)
                || ! array_key_exists('parameters', $envelope)
                || $envelopeKeys !== ['parameters', 'value']
                || ! is_array($this->native($envelope['parameters']))) {
                throw new RuntimeException("tenant.account_profile_capabilities_v1 refuses malformed typed capability [{$key}].");
            }
            $definition = $definitions[$key] ?? null;
            if (! is_array($definition) || ! $this->validValue($definition, $envelope['value'])) {
                throw new RuntimeException("tenant.account_profile_capabilities_v1 refuses malformed typed capability value [{$key}].");
            }

            $parameters = $this->document($envelope['parameters']);
            $parameterDefinitions = [];
            foreach ($definition['parameters'] as $parameter) {
                $parameterDefinitions[$parameter['key']] = $parameter;
            }
            $parameterKeys = array_keys($parameters);
            sort($parameterKeys, SORT_STRING);
            $expectedParameterKeys = array_keys($parameterDefinitions);
            sort($expectedParameterKeys, SORT_STRING);
            if ($parameterKeys !== $expectedParameterKeys) {
                throw new RuntimeException("tenant.account_profile_capabilities_v1 refuses malformed typed capability parameters [{$key}].");
            }
            foreach ($parameters as $parameterKey => $value) {
                if (! $this->validParameterValue($parameterDefinitions[$parameterKey], $value)) {
                    throw new RuntimeException("tenant.account_profile_capabilities_v1 refuses malformed typed capability parameter [{$key}.{$parameterKey}].");
                }
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function validValue(array $definition, mixed $value): bool
    {
        return match ($definition['value_type']) {
            'boolean' => is_bool($value),
            'enum' => is_string($value) && in_array($value, $definition['allowed_values'], true),
            default => false,
        };
    }

    /** @param array<string, mixed> $parameter */
    private function validParameterValue(array $parameter, mixed $value): bool
    {
        if (($parameter['value_type'] ?? null) !== 'integer' || ! is_int($value)) {
            return false;
        }
        foreach ($parameter['validations'] as $validation) {
            if ($validation['rule'] === 'min' && $value < $validation['value']) {
                return false;
            }
            if ($validation['rule'] === 'max' && $value > $validation['value']) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function definitionForPersistence(array $definition): array
    {
        $resources = [];
        foreach ($definition['resources'] as $key => $resource) {
            $resources[$key] = new BSONDocument([
                'operations' => $resource['operations'],
            ]);
        }

        return [
            ...$definition,
            'resources' => new BSONDocument($resources),
        ];
    }

    /** @param array<string, mixed> $configuration */
    private function configurationForPersistence(array $configuration): BSONDocument
    {
        $stored = [];
        foreach ($configuration as $key => $rawEnvelope) {
            $envelope = $this->document($rawEnvelope);
            $stored[$key] = new BSONDocument([
                'value' => $envelope['value'],
                'parameters' => new BSONDocument($this->document($envelope['parameters'] ?? [])),
            ]);
        }

        return new BSONDocument($stored);
    }

    private function convergeTypeIndexes(MongoDB\Collection $types): void
    {
        foreach ($types->listIndexes() as $index) {
            $name = $index->getName();
            if ($name === '_id_' || $name === 'type_1') {
                continue;
            }
            $keys = $this->native($index->getKey());
            if (array_filter(array_keys($keys), static fn (string $key): bool => str_starts_with($key, 'capabilities.')) !== []) {
                $types->dropIndex($name);
            }
        }

        foreach ($this->typeIndexes() as $name => $keys) {
            $types->createIndex($keys, ['name' => $name]);
        }
    }

    /**
     * @param  array<string, array{keys:array<string, int>,unique:bool}>  $plan
     */
    private function assertIndexPlan(
        MongoDB\Collection $collection,
        array $plan,
        bool $allowEquivalentDifferentName = false,
    ): void {
        foreach ($collection->listIndexes() as $index) {
            $name = $index->getName();
            $keys = $this->native($index->getKey());
            if (isset($plan[$name])) {
                if ($keys !== $plan[$name]['keys'] || $index->isUnique() !== $plan[$name]['unique']) {
                    if ($allowEquivalentDifferentName && $this->isCapabilityIndex($keys)) {
                        continue;
                    }
                    throw new RuntimeException("tenant.account_profile_capabilities_v1 refuses conflicting index [{$name}].");
                }

                continue;
            }
            if ($allowEquivalentDifferentName) {
                continue;
            }
            foreach ($plan as $targetName => $target) {
                if ($keys === $target['keys']) {
                    throw new RuntimeException("tenant.account_profile_capabilities_v1 refuses equivalent index [{$name}] for [{$targetName}].");
                }
            }
        }
    }

    /** @param array<string, mixed> $keys */
    private function isCapabilityIndex(array $keys): bool
    {
        return array_filter(
            array_keys($keys),
            static fn (string $key): bool => str_starts_with($key, 'capabilities.'),
        ) !== [];
    }

    /** @return array<string, array<string, int>> */
    private function typeIndexes(): array
    {
        return [
            'idx_account_profile_types_candidate_queryable_v2' => ['capabilities.is_queryable.value' => 1, 'type' => 1],
            'idx_account_profile_types_candidate_contact_capable_v2' => ['capabilities.has_contact_channels.value' => 1, 'type' => 1],
            'idx_account_profile_types_public_navigation_v2' => ['capabilities.is_publicly_navigable.value' => 1, 'type' => 1],
            'idx_account_profile_types_public_discovery_v2' => ['capabilities.is_queryable.value' => 1, 'capabilities.is_publicly_discoverable.value' => 1, 'type' => 1],
            'idx_account_profile_types_map_poi_location_policy_v1' => ['capabilities.is_map_poi_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1],
            'idx_account_profile_types_physical_host_location_policy_v1' => ['capabilities.is_physical_host_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1],
            'idx_account_profile_types_reference_location_policy_v1' => ['capabilities.is_reference_location_enabled.value' => 1, 'capabilities.location_policy.value' => 1, 'type' => 1],
            'idx_account_profile_types_location_policy_v1' => ['capabilities.location_policy.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_is_publicly_discoverable_v1' => ['capabilities.is_publicly_discoverable.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_is_favoritable_v1' => ['capabilities.is_favoritable.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_is_inviteable_v1' => ['capabilities.is_inviteable.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_has_bio_v1' => ['capabilities.has_bio.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_has_taxonomies_v1' => ['capabilities.has_taxonomies.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_has_avatar_v1' => ['capabilities.has_avatar.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_has_cover_v1' => ['capabilities.has_cover.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_has_events_v1' => ['capabilities.has_events.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_has_gallery_v1' => ['capabilities.has_gallery.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_has_nested_profile_groups_v1' => ['capabilities.has_nested_profile_groups.value' => 1, 'type' => 1],
            'idx_account_profile_types_capability_has_external_links_v1' => ['capabilities.has_external_links.value' => 1, 'type' => 1],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function definitions(): array
    {
        $definitions = [
            $this->boolean('has_avatar', 'profile_content'),
            $this->boolean('has_bio', 'profile_content'),
            $this->boolean('has_contact_channels', 'profile_content'),
            $this->boolean('has_cover', 'profile_content'),
            $this->boolean('has_events', 'events'),
            $this->externalLinks(),
            $this->gallery(),
            $this->boolean('has_nested_profile_groups', 'relationships'),
            $this->boolean('has_taxonomies', 'profile_content'),
            $this->boolean('is_favoritable', 'relationships'),
            $this->boolean('is_inviteable', 'relationships'),
            $this->boolean('is_map_poi_enabled', 'location', locationDependent: true),
            $this->boolean('is_physical_host_enabled', 'location', locationDependent: true),
            $this->boolean('is_publicly_discoverable', 'visibility', true),
            $this->boolean('is_publicly_navigable', 'visibility', true),
            $this->boolean('is_queryable', 'relationships', true),
            $this->boolean('is_reference_location_enabled', 'location', locationDependent: true),
            [
                'key' => 'location_policy', 'domain' => 'location', 'value_type' => 'enum',
                'default_value' => 'disabled', 'fail_closed_value' => 'disabled',
                'allowed_values' => ['disabled', 'optional', 'required'],
                'parameters' => [], 'resources' => [],
            ],
        ];
        usort($definitions, static fn (array $left, array $right): int => $left['key'] <=> $right['key']);

        return $definitions;
    }

    /** @return array<string, mixed> */
    private function boolean(string $key, string $domain, bool $default = false, bool $locationDependent = false): array
    {
        return [
            'key' => $key, 'domain' => $domain, 'value_type' => 'boolean',
            'default_value' => $default, 'fail_closed_value' => false,
            ...($locationDependent ? ['dependencies' => [[
                'capability_key' => 'location_policy',
                'accepted_values' => ['optional', 'required'],
            ]]] : []),
            'parameters' => [], 'resources' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function externalLinks(): array
    {
        return [
            ...$this->boolean('has_external_links', 'profile_content'),
            'parameters' => [[
                'key' => 'max_links', 'value_type' => 'integer', 'default_value' => 3,
                'fail_closed_value' => 0, 'validations' => [['rule' => 'min', 'value' => 0]],
            ]],
            'resources' => ['external_links' => ['operations' => $this->operations(['create', 'update', 'delete'])]],
        ];
    }

    /** @return array<string, mixed> */
    private function gallery(): array
    {
        $operations = $this->operations(['create', 'update', 'delete', 'reorder']);

        return [
            ...$this->boolean('has_gallery', 'profile_content'),
            'parameters' => [
                ['key' => 'max_groups', 'value_type' => 'integer', 'default_value' => 6, 'fail_closed_value' => 0, 'validations' => [['rule' => 'min', 'value' => 0]]],
                ['key' => 'max_items_per_group', 'value_type' => 'integer', 'default_value' => 12, 'fail_closed_value' => 0, 'validations' => [['rule' => 'min', 'value' => 0]]],
            ],
            'resources' => [
                'gallery_groups' => ['operations' => $operations],
                'gallery_items' => ['operations' => $operations],
            ],
        ];
    }

    /** @param array<int, string> $keys @return array<int, array{key:string,ability:string}> */
    private function operations(array $keys): array
    {
        return array_map(
            static fn (string $key): array => ['key' => $key, 'ability' => 'account-users:update'],
            $keys,
        );
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
