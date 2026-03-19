<?php

declare(strict_types=1);

namespace Belluga\MapPois\Application\Concerns;

use Illuminate\Contracts\Support\Arrayable;
use MongoDB\BSON\UTCDateTime;

trait MapPoiQueryFormatting
{
    /**
     * @return array<string, mixed>
     */
    private function formatStackFromAggregate(mixed $stack): array
    {
        $payloadData = $this->normalizeDocument($stack);
        $center = $this->formatLocation($payloadData['center'] ?? null);
        $topPoi = $this->formatTopPoi($payloadData['top_poi'] ?? null);

        return [
            'stack_key' => (string) ($payloadData['stack_key'] ?? $payloadData['_id'] ?? ''),
            'center' => $center,
            'stack_count' => (int) ($payloadData['stack_count'] ?? 0),
            'top_poi' => $topPoi,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function formatStack(string $stackKey, array $items): ?array
    {
        if ($items === []) {
            return null;
        }

        $top = $items[0];

        return [
            'stack_key' => $stackKey,
            'center' => $top['location'] ?? null,
            'stack_count' => count($items),
            'top_poi' => $top,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTopPoi(mixed $item): array
    {
        $payloadData = $this->normalizeDocument($item);
        $location = $this->formatLocation($payloadData['location'] ?? null);
        $distance = isset($payloadData['distance_meters']) ? (float) $payloadData['distance_meters'] : null;
        $name = (string) ($payloadData['name'] ?? $payloadData['title'] ?? '');
        $subtitleRaw = $payloadData['subtitle'] ?? $payloadData['description'] ?? $payloadData['address'] ?? null;
        $subtitle = is_string($subtitleRaw) && trim($subtitleRaw) !== '' ? trim($subtitleRaw) : null;

        $payload = [
            'ref_type' => (string) ($payloadData['ref_type'] ?? ''),
            'ref_id' => (string) ($payloadData['ref_id'] ?? ''),
            'ref_slug' => (string) ($payloadData['ref_slug'] ?? ''),
            'ref_path' => (string) ($payloadData['ref_path'] ?? ''),
            'title' => $name,
            'subtitle' => $subtitle,
            'name' => $name,
            'description' => $subtitle,
            'address' => $subtitle,
            'category' => (string) ($payloadData['category'] ?? ''),
            'source_type' => isset($payloadData['source_type']) ? (string) $payloadData['source_type'] : null,
            'location' => $location,
            'is_happening_now' => (bool) ($payloadData['is_happening_now'] ?? false),
            'priority' => (int) ($payloadData['priority'] ?? 0),
            'updated_at' => $this->formatDate($payloadData['updated_at'] ?? null),
            'time_start' => $this->formatDate($payloadData['time_start'] ?? null),
            'time_end' => $this->formatDate($payloadData['time_end'] ?? null),
            'avatar_url' => $payloadData['avatar_url'] ?? null,
            'cover_url' => $payloadData['cover_url'] ?? null,
            'badge' => $payloadData['badge'] ?? null,
        ];

        if ($distance !== null) {
            $payload['distance_meters'] = $distance;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatNearItem(mixed $item): array
    {
        $payloadData = $this->normalizeDocument($item);
        $location = $this->formatLocation($payloadData['location'] ?? null);
        $distance = isset($payloadData['distance_meters']) ? (float) $payloadData['distance_meters'] : null;
        $title = (string) ($payloadData['name'] ?? $payloadData['title'] ?? '');
        $subtitleRaw = $payloadData['subtitle'] ?? $payloadData['description'] ?? $payloadData['address'] ?? null;
        $subtitle = is_string($subtitleRaw) && trim($subtitleRaw) !== '' ? trim($subtitleRaw) : null;

        return [
            'ref_type' => (string) ($payloadData['ref_type'] ?? ''),
            'ref_id' => (string) ($payloadData['ref_id'] ?? ''),
            'ref_slug' => (string) ($payloadData['ref_slug'] ?? ''),
            'ref_path' => (string) ($payloadData['ref_path'] ?? ''),
            'title' => $title,
            'subtitle' => $subtitle,
            'category' => (string) ($payloadData['category'] ?? ''),
            'location' => $location,
            'distance_meters' => $distance,
            'is_happening_now' => (bool) ($payloadData['is_happening_now'] ?? false),
            'updated_at' => $this->formatDate($payloadData['updated_at'] ?? null),
            'time_start' => $this->formatDate($payloadData['time_start'] ?? null),
            'time_end' => $this->formatDate($payloadData['time_end'] ?? null),
            'avatar_url' => $payloadData['avatar_url'] ?? null,
            'cover_url' => $payloadData['cover_url'] ?? null,
            'badge' => $payloadData['badge'] ?? null,
            'tags' => $this->normalizeStringArray($payloadData['tags'] ?? []),
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($payloadData['taxonomy_terms'] ?? []),
            'occurrence_facets' => $this->formatOccurrenceFacets($payloadData['occurrence_facets'] ?? []),
        ];
    }

    /**
     * @param  array<int, mixed>  $facets
     * @return array<int, array<string, mixed>>
     */
    private function formatOccurrenceFacets(array $facets): array
    {
        $normalized = [];

        foreach ($facets as $facet) {
            if (! is_array($facet)) {
                continue;
            }

            $normalized[] = [
                'occurrence_id' => (string) ($facet['occurrence_id'] ?? ''),
                'occurrence_slug' => isset($facet['occurrence_slug']) ? (string) $facet['occurrence_slug'] : null,
                'starts_at' => (string) ($facet['starts_at'] ?? ''),
                'ends_at' => isset($facet['ends_at']) ? (string) $facet['ends_at'] : null,
                'effective_end' => isset($facet['effective_end']) ? (string) $facet['effective_end'] : null,
                'is_happening_now' => (bool) ($facet['is_happening_now'] ?? false),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, float>|null
     */
    private function formatLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        return [
            'lat' => (float) $coordinates[1],
            'lng' => (float) $coordinates[0],
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format(DATE_ATOM);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDocument(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if (is_object($value)) {
            if (method_exists($value, 'getArrayCopy')) {
                $copy = $value->getArrayCopy();
                if (is_array($copy)) {
                    return $copy;
                }
            }

            return get_object_vars($value);
        }

        return [];
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeStringArray(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $item = trim((string) $value);
            if ($item === '') {
                continue;
            }
            $normalized[] = $item;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<int, mixed>  $terms
     * @return array<int, array<string, string>>
     */
    private function normalizeTaxonomyTerms(array $terms): array
    {
        $normalized = [];

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }
            $normalized[] = [
                'type' => $type,
                'value' => $value,
            ];
        }

        return $normalized;
    }
}
