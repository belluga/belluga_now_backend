<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** A forward-only tenant migration with its preflight kept local. */
    public function up(): void
    {
        $database = DB::connection('tenant')->getDatabase();
        $collections = [];
        foreach ($database->listCollections() as $collection) {
            $collections[$collection->getName()] = true;
        }

        // No authored Static record is disposable by this migration. A
        // contradiction must leave every collection unchanged for Product.
        if (isset($collections['static_assets']) && $database->selectCollection('static_assets')->countDocuments() !== 0) {
            throw new RuntimeException('tenant.static_retirement_v1 refuses non-empty static_assets.');
        }
        if (isset($collections['static_profile_types'])) {
            foreach ($database->selectCollection('static_profile_types')->find() as $type) {
                if (! $this->isHistoricalPoiSeed((array) $type)) {
                    throw new RuntimeException('tenant.static_retirement_v1 refuses authored static_profile_types.');
                }
            }
        }

        $mixedTaxonomies = [];
        if (isset($collections['taxonomies'])) {
            foreach ($database->selectCollection('taxonomies')->find(['applies_to' => 'static_asset']) as $taxonomy) {
                $values = $this->normalizeValue($taxonomy['applies_to'] ?? null);
                if (! is_array($values) || ! array_is_list($values)
                    || array_filter($values, static fn (mixed $value): bool => ! is_string($value)) !== []) {
                    throw new RuntimeException('tenant.static_retirement_v1 refuses malformed taxonomy applies_to.');
                }
                $survivors = array_values(array_filter($values, static fn (string $value): bool => $value !== 'static_asset'));
                if ($survivors === []) {
                    throw new RuntimeException('tenant.static_retirement_v1 refuses static-only taxonomy.');
                }
                $mixedTaxonomies[] = ['_id' => $taxonomy['_id'], 'applies_to' => $survivors];
            }
        }

        if (isset($collections['map_pois'])) {
            foreach ($database->selectCollection('map_pois')->find(['ref_type' => 'static']) as $poi) {
                $this->assertHistoricalStaticProjection((array) $poi);
            }
        }

        $settingsCleanup = null;
        if (isset($collections['settings'])) {
            $settings = $database->selectCollection('settings')->findOne(['_id' => 'settings_root']);
            if ($settings !== null) {
                $settingsCleanup = $this->withoutStaticFilters((array) $settings);
            }
        }

        foreach ($mixedTaxonomies as $taxonomy) {
            $database->selectCollection('taxonomies')->updateOne(
                ['_id' => $taxonomy['_id']],
                ['$set' => ['applies_to' => $taxonomy['applies_to']]],
            );
        }
        if (isset($collections['map_pois'])) {
            $database->selectCollection('map_pois')->deleteMany(['ref_type' => 'static']);
        }
        if ($settingsCleanup !== null && $settingsCleanup !== []) {
            $database->selectCollection('settings')->updateOne(
                ['_id' => 'settings_root'],
                ['$set' => $settingsCleanup],
            );
        }
        if (isset($collections['environment_snapshots'])) {
            // Snapshots are derived and the normal read path rebuilds a missing
            // root document from the surviving settings/providers.
            $database->selectCollection('environment_snapshots')->deleteOne(['_id' => 'settings_root']);
        }
        foreach (['static_assets', 'static_profile_types'] as $name) {
            if (isset($collections[$name])) {
                $database->dropCollection($name);
            }
        }
    }

    public function down(): void {}

    /** @param array<string, mixed> $document */
    private function isHistoricalPoiSeed(array $document): bool
    {
        $document = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        foreach (['_id', 'created_at', 'updated_at', 'deleted_at'] as $field) {
            unset($document[$field]);
        }
        $base = [
            'type' => 'poi', 'label' => 'POI', 'allowed_taxonomies' => [],
            'capabilities' => ['is_poi_enabled' => true, 'has_bio' => true, 'has_taxonomies' => true, 'has_avatar' => true, 'has_cover' => true, 'has_content' => true],
        ];

        $document = $this->canonicalizeDocument($document);
        $variants = [
            $base,
            $base + ['map_category' => 'poi'],
            $base + ['map_category' => 'poi', 'poi_visual' => ['mode' => 'icon', 'icon' => 'place', 'color' => '#1E88E5']],
        ];

        return in_array(
            $document,
            array_map(fn (array $variant): array => $this->canonicalizeDocument($variant), $variants),
            true,
        );
    }

    /** @param array<string, mixed> $document */
    private function assertHistoricalStaticProjection(array $document): void
    {
        $refId = trim((string) ($document['ref_id'] ?? ''));
        if ($refId === '' || ($document['projection_key'] ?? null) !== "static:{$refId}") {
            throw new RuntimeException('tenant.static_retirement_v1 refuses malformed or unresolved static map projection.');
        }
    }

    /** @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function withoutStaticFilters(array $document): array
    {
        $cleanup = [];
        $mapUi = $this->normalizeValue($document['map_ui'] ?? null);
        if (is_array($mapUi) && array_is_list($mapUi) === false && isset($mapUi['filters'])) {
            $mapUi['filters'] = $this->cleanFilterList($mapUi['filters'], legacy: true);
            $cleanup['map_ui'] = $mapUi;
        }

        $discovery = $this->normalizeValue($document['discovery_filters'] ?? null);
        if (is_array($discovery) && array_is_list($discovery) === false) {
            $surfaces = $discovery['surfaces'] ?? null;
            if (is_array($surfaces) && array_is_list($surfaces) === false) {
                foreach ($surfaces as $key => $surface) {
                    if (is_array($surface) && array_is_list($surface) === false && isset($surface['filters'])) {
                        $surface['filters'] = $this->cleanFilterList($surface['filters'], legacy: false);
                        $surfaces[$key] = $surface;
                    }
                }
                $discovery['surfaces'] = $surfaces;
                $cleanup['discovery_filters'] = $discovery;
            }
        }

        return $cleanup;
    }

    private function cleanFilterList(mixed $filters, bool $legacy): array
    {
        $filters = $this->normalizeValue($filters);
        if (! is_array($filters) || ! array_is_list($filters)) {
            throw new RuntimeException('tenant.static_retirement_v1 refuses malformed persisted filter list.');
        }

        $clean = [];
        foreach ($filters as $filter) {
            if (! is_array($filter) || array_is_list($filter)) {
                throw new RuntimeException('tenant.static_retirement_v1 refuses malformed persisted filter entry.');
            }
            $query = $filter['query'] ?? [];
            if (! is_array($query) || array_is_list($query)) {
                throw new RuntimeException('tenant.static_retirement_v1 refuses malformed persisted filter query.');
            }

            if ($legacy) {
                $sources = $this->stringList($query['source'] ?? null);
                $survivors = array_values(array_filter($sources, fn (string $value): bool => ! $this->isStaticToken($value)));
                if ($sources !== $survivors) {
                    if ($survivors === []) {
                        continue;
                    }
                    $query['source'] = is_array($query['source'] ?? null) ? $survivors : $survivors[0];
                    $filter['query'] = $query;
                }
            } else {
                $entities = $this->stringList($query['entities'] ?? []);
                $survivors = array_values(array_filter($entities, fn (string $value): bool => ! $this->isStaticToken($value)));
                foreach (array_keys($query['types_by_entity'] ?? []) as $entity) {
                    if (is_string($entity) && $this->isStaticToken($entity)) {
                        unset($query['types_by_entity'][$entity]);
                    }
                }
                if ($entities !== $survivors) {
                    if ($survivors === []) {
                        continue;
                    }
                    $query['entities'] = $survivors;
                    $filter['query'] = $query;
                }
            }
            $clean[] = $filter;
        }

        return $clean;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        $value = $this->normalizeValue($value);
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value) || ! array_is_list($value)
            || array_filter($value, static fn (mixed $item): bool => ! is_string($item)) !== []) {
            throw new RuntimeException('tenant.static_retirement_v1 refuses malformed persisted filter source.');
        }

        return $value;
    }

    private function isStaticToken(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['static', 'static_asset', 'static_assets'], true);
    }

    /** @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function canonicalizeDocument(array $document): array
    {
        foreach ($document as $key => $value) {
            if (is_array($value)) {
                $document[$key] = array_is_list($value)
                    ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->canonicalizeDocument($item) : $item, $value)
                    : $this->canonicalizeDocument($value);
            }
        }
        if (! array_is_list($document)) {
            ksort($document);
        }

        return $document;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        } elseif (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
    }
};
