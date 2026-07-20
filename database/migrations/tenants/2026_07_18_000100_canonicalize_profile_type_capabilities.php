<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONDocument;

return new class extends Migration
{
    /** @var array<string, bool> */
    private const DEFAULT_CAPABILITIES = [
        'is_queryable' => true,
        'is_publicly_navigable' => true,
        'is_publicly_discoverable' => true,
        'is_favoritable' => false,
        'is_inviteable' => false,
        'is_poi_enabled' => false,
        'is_reference_location_enabled' => false,
        'has_bio' => false,
        'has_content' => false,
        'has_taxonomies' => false,
        'has_avatar' => false,
        'has_cover' => false,
        'has_events' => false,
        'has_gallery' => false,
        'has_nested_profile_groups' => false,
        'has_contact_channels' => false,
    ];

    /** @var array<string, array<string, bool>> */
    private const TYPE_DEFAULT_OVERRIDES = [
        'personal' => [
            'is_queryable' => false,
            'is_publicly_navigable' => false,
            'is_favoritable' => true,
            'is_inviteable' => true,
            'is_publicly_discoverable' => false,
            'is_poi_enabled' => false,
            'has_content' => false,
            'has_gallery' => false,
        ],
        'artist' => [
            'is_favoritable' => true,
            'has_gallery' => true,
        ],
        'venue' => [
            'is_favoritable' => true,
            'is_poi_enabled' => true,
            'has_gallery' => true,
        ],
    ];

    /** @var array<int, array{name:string, keys:array<string, int>}> */
    private const INDEX_DEFINITIONS = [
        ['name' => 'idx_account_profile_types_capability_is_queryable_v1', 'keys' => ['capabilities.is_queryable' => 1]],
        ['name' => 'idx_account_profile_types_capability_is_publicly_navigable_v1', 'keys' => ['capabilities.is_publicly_navigable' => 1]],
        ['name' => 'idx_account_profile_types_capability_is_publicly_discoverable_v1', 'keys' => ['capabilities.is_publicly_discoverable' => 1]],
        ['name' => 'idx_account_profile_types_capability_is_favoritable_v1', 'keys' => ['capabilities.is_favoritable' => 1]],
        ['name' => 'idx_account_profile_types_capability_is_inviteable_v1', 'keys' => ['capabilities.is_inviteable' => 1]],
        ['name' => 'idx_account_profile_types_capability_is_poi_enabled_v1', 'keys' => ['capabilities.is_poi_enabled' => 1]],
        ['name' => 'idx_account_profile_types_capability_is_reference_location_enabled_v1', 'keys' => ['capabilities.is_reference_location_enabled' => 1]],
        ['name' => 'idx_account_profile_types_capability_has_bio_v1', 'keys' => ['capabilities.has_bio' => 1]],
        ['name' => 'idx_account_profile_types_capability_has_content_v1', 'keys' => ['capabilities.has_content' => 1]],
        ['name' => 'idx_account_profile_types_capability_has_taxonomies_v1', 'keys' => ['capabilities.has_taxonomies' => 1]],
        ['name' => 'idx_account_profile_types_capability_has_avatar_v1', 'keys' => ['capabilities.has_avatar' => 1]],
        ['name' => 'idx_account_profile_types_capability_has_cover_v1', 'keys' => ['capabilities.has_cover' => 1]],
        ['name' => 'idx_account_profile_types_capability_has_events_v1', 'keys' => ['capabilities.has_events' => 1]],
        ['name' => 'idx_account_profile_types_capability_has_gallery_v1', 'keys' => ['capabilities.has_gallery' => 1]],
        ['name' => 'idx_account_profile_types_capability_has_nested_profile_groups_v1', 'keys' => ['capabilities.has_nested_profile_groups' => 1]],
        ['name' => 'idx_account_profile_types_capability_has_contact_channels_v1', 'keys' => ['capabilities.has_contact_channels' => 1]],
        ['name' => 'idx_account_profile_types_queryable_candidates_v1', 'keys' => ['capabilities.is_queryable' => 1, 'type' => 1]],
        ['name' => 'idx_account_profile_types_public_navigation_v1', 'keys' => ['capabilities.is_publicly_navigable' => 1, 'type' => 1]],
        ['name' => 'idx_account_profile_types_public_discovery_v1', 'keys' => ['capabilities.is_queryable' => 1, 'capabilities.is_publicly_discoverable' => 1, 'type' => 1]],
        ['name' => 'idx_account_profile_types_public_catalog_v2', 'keys' => ['capabilities.is_queryable' => 1, 'capabilities.is_publicly_discoverable' => 1, 'capabilities.is_favoritable' => 1, 'type' => 1]],
        ['name' => 'idx_account_profile_types_public_poi_catalog_v2', 'keys' => ['capabilities.is_queryable' => 1, 'capabilities.is_publicly_discoverable' => 1, 'capabilities.is_favoritable' => 1, 'capabilities.is_poi_enabled' => 1, 'type' => 1]],
        ['name' => 'idx_account_profile_types_queryable_poi_enabled_v1', 'keys' => ['capabilities.is_queryable' => 1, 'capabilities.is_poi_enabled' => 1, 'type' => 1]],
        ['name' => 'idx_account_profile_types_queryable_public_navigation_poi_enabled_v1', 'keys' => ['capabilities.is_queryable' => 1, 'capabilities.is_publicly_navigable' => 1, 'capabilities.is_poi_enabled' => 1, 'type' => 1]],
        ['name' => 'idx_account_profile_types_contact_channels_v1', 'keys' => ['capabilities.has_contact_channels' => 1, 'type' => 1]],
    ];

    /** @var array<string, string> */
    private const LEGACY_INDEX_SUCCESSORS = [
        'idx_account_profile_types_queryable_candidates_v1' => 'idx_account_profile_types_candidate_queryable_v1',
        'idx_account_profile_types_contact_channels_v1' => 'idx_account_profile_types_candidate_contact_capable_v1',
    ];

    public function up(): void
    {
        if (! Schema::hasCollection('account_profile_types')) {
            return;
        }

        $collection = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_types');

        $this->repairCapabilities($collection, new UTCDateTime((int) Carbon::now()->getTimestampMs()));
        $this->provisionIndexes($collection);
    }

    public function down(): void
    {
        // Capability completion is additive and must not erase persisted tenant intent.
    }

    private function repairCapabilities(\MongoDB\Collection $collection, UTCDateTime $now): void
    {
        $typeSpecificTypes = array_keys(self::TYPE_DEFAULT_OVERRIDES);

        foreach ($typeSpecificTypes as $type) {
            $this->repairFields(
                $collection,
                ['type' => $type],
                $this->defaultsFor($type),
                $now,
            );
        }

        $this->repairFields(
            $collection,
            ['type' => ['$nin' => $typeSpecificTypes]],
            $this->defaultsFor(''),
            $now,
        );
    }

    /**
     * @param  array<string, mixed>  $baseFilter
     * @param  array<string, bool>  $capabilities
     */
    private function repairFields(
        \MongoDB\Collection $collection,
        array $baseFilter,
        array $capabilities,
        UTCDateTime $now,
    ): void {
        foreach ($capabilities as $capability => $default) {
            // Each document rechecks repairability atomically when Mongo applies this update.
            $collection->updateMany(
                array_merge($baseFilter, [
                    "capabilities.{$capability}" => ['$not' => ['$type' => 'bool']],
                ]),
                ['$set' => [
                    "capabilities.{$capability}" => $default,
                    'updated_at' => $now,
                ]],
            );
        }
    }

    private function provisionIndexes(\MongoDB\Collection $collection): void
    {
        foreach ([
            'capabilities.is_favoritable_1',
            'capabilities.is_poi_enabled_1',
            'idx_account_profile_types_inviteable_v1',
            'idx_account_profile_types_public_catalog_v1',
        ] as $legacyIndex) {
            $this->dropIndexIfPresent($collection, $legacyIndex);
        }

        $indexOptions = ['collation' => ['locale' => 'simple']];
        foreach (self::INDEX_DEFINITIONS as $definition) {
            if ($this->hasExactSuccessorIndex($collection, $definition)) {
                continue;
            }

            $collection->createIndex($definition['keys'], [
                ...$indexOptions,
                'name' => $definition['name'],
            ]);
        }
    }

    /** @return array<string, bool> */
    private function defaultsFor(string $type): array
    {
        return array_replace(
            self::DEFAULT_CAPABILITIES,
            self::TYPE_DEFAULT_OVERRIDES[trim($type)] ?? [],
        );
    }

    private function dropIndexIfPresent(\MongoDB\Collection $collection, string $name): void
    {
        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() === $name) {
                $collection->dropIndex($name);

                return;
            }
        }
    }

    /** @param array{name:string, keys:array<string, int>} $definition */
    private function hasExactSuccessorIndex(\MongoDB\Collection $collection, array $definition): bool
    {
        $successor = self::LEGACY_INDEX_SUCCESSORS[$definition['name']] ?? null;
        if ($successor === null) {
            return false;
        }

        foreach ($collection->listIndexes() as $index) {
            if ($index->getName() !== $successor) {
                continue;
            }

            return $this->documentToArray($index->getKey()) === $definition['keys'];
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function documentToArray(mixed $document): array
    {
        if (is_array($document)) {
            return $document;
        }

        if ($document instanceof BSONDocument) {
            return $document->getArrayCopy();
        }

        if ($document instanceof \Traversable) {
            return iterator_to_array($document);
        }

        return [];
    }
};
