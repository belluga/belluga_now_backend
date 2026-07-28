<?php

declare(strict_types=1);

use App\Application\AccountProfiles\AccountProfileTypeCapabilityCatalog;
use App\Application\AccountProfiles\AccountProfileTypeCapabilityRepairer;
use App\Application\AccountProfiles\AccountProfileTypeIndexManifest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

return new class extends Migration
{
    public function up(): void
    {
        /** @var Collection<array<string, mixed>> $collection */
        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        $catalog = new AccountProfileTypeCapabilityCatalog;
        $repairer = new AccountProfileTypeCapabilityRepairer($catalog);
        $now = new UTCDateTime((int) now()->getTimestampMs());

        foreach ($this->repairGroups($repairer) as $group) {
            $this->repairFields($collection, $group['filter'], $group['defaults'], $now);
        }

        $this->dropLegacyIndexes($collection);
        $this->ensureCanonicalIndexes($collection);
    }

    public function down(): void
    {
    }

    /**
     * @param  Collection<array<string, mixed>>  $collection
     * @param  array<string, mixed>  $baseFilter
     * @param  array<string, bool>  $defaults
     */
    private function repairFields(Collection $collection, array $baseFilter, array $defaults, UTCDateTime $now): void
    {
        $repairer = new AccountProfileTypeCapabilityRepairer(new AccountProfileTypeCapabilityCatalog);

        foreach ($defaults as $key => $value) {
            $collection->updateMany(
                array_merge($baseFilter, $repairer->repairableFieldFilter($key)),
                ['$set' => [
                    "capabilities.{$key}" => $value,
                    'updated_at' => $now,
                ]],
            );
        }
    }

    /**
     * @return array<int, array{filter:array<string, mixed>, defaults:array<string, bool>}>
     */
    private function repairGroups(AccountProfileTypeCapabilityRepairer $repairer): array
    {
        return [
            [
                'filter' => ['type' => 'artist'],
                'defaults' => $repairer->defaultsForType('artist'),
            ],
            [
                'filter' => ['type' => ['$nin' => ['personal', 'artist', 'venue']]],
                'defaults' => $repairer->defaultsForType('custom'),
            ],
            [
                'filter' => ['type' => 'personal'],
                'defaults' => $repairer->defaultsForType('personal'),
            ],
            [
                'filter' => ['type' => 'venue'],
                'defaults' => $repairer->defaultsForType('venue'),
            ],
        ];
    }

    /**
     * @param  Collection<array<string, mixed>>  $collection
     */
    private function dropLegacyIndexes(Collection $collection): void
    {
        foreach ([
            'idx_account_profile_types_queryable_candidates_v1',
            'idx_account_profile_types_contact_channels_v1',
        ] as $indexName) {
            try {
                $collection->dropIndex($indexName);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param  Collection<array<string, mixed>>  $collection
     */
    private function ensureCanonicalIndexes(Collection $collection): void
    {
        foreach ((new AccountProfileTypeIndexManifest)->definitions() as $definition) {
            $existing = $this->currentIndexes($collection);
            $namedIndex = $this->namedIndex($existing, $definition['name']);
            if ($namedIndex !== null && $namedIndex['keys'] === $definition['keys']) {
                continue;
            }

            if ($namedIndex !== null) {
                $this->dropIndexIfPresent($collection, $definition['name']);
                $existing = $this->currentIndexes($collection);
            }

            foreach ($this->indexesMatchingKeys($existing, $definition['keys'], $definition['name']) as $index) {
                if (
                    $index['name'] !== '_id_'
                ) {
                    $this->dropIndexIfPresent($collection, $index['name']);
                }
            }

            $collection->createIndex($definition['keys'], [
                'name' => $definition['name'],
                'collation' => $definition['collation'],
            ]);
        }
    }

    /**
     * @param  Collection<array<string, mixed>>  $collection
     * @return list<array{name:string, keys:array<string, int>}>
     */
    private function currentIndexes(Collection $collection): array
    {
        $existing = [];
        foreach ($collection->listIndexes() as $index) {
            $existing[] = [
                'name' => (string) $index->getName(),
                'keys' => $this->arrayFrom($index->getKey()),
            ];
        }

        return $existing;
    }

    /**
     * @param  list<array{name:string, keys:array<string, int>}>  $indexes
     */
    private function namedIndex(array $indexes, string $name): ?array
    {
        foreach ($indexes as $index) {
            if ($index['name'] === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<array{name:string, keys:array<string, int>}>  $indexes
     * @return list<array{name:string, keys:array<string, int>}>
     */
    private function indexesMatchingKeys(array $indexes, array $keys, string $canonicalName): array
    {
        return array_values(array_filter(
            $indexes,
            static fn (array $index): bool => $index['name'] !== '_id_'
                && $index['name'] !== $canonicalName
                && $index['keys'] === $keys,
        ));
    }

    /**
     * @param  Collection<array<string, mixed>>  $collection
     */
    private function dropIndexIfPresent(Collection $collection, string $name): void
    {
        try {
            $collection->dropIndex($name);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, int>
     */
    private function arrayFrom(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }

        return is_array($value) ? $value : [];
    }
};
