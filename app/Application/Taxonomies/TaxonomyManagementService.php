<?php

declare(strict_types=1);

namespace App\Application\Taxonomies;

use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use Illuminate\Validation\ValidationException;

class TaxonomyManagementService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $slug = $this->normalizeSlug($payload['slug'] ?? '');
        if (Taxonomy::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['Taxonomy slug already exists.'],
            ]);
        }

        $taxonomy = Taxonomy::create($this->buildEntry($payload, $slug));

        return $this->toPayload($taxonomy);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(string $taxonomyId, array $payload): array
    {
        $taxonomy = Taxonomy::query()->where('_id', $taxonomyId)->first();
        if (! $taxonomy) {
            abort(404, 'Taxonomy not found.');
        }

        $slug = array_key_exists('slug', $payload)
            ? $this->normalizeSlug($payload['slug'])
            : (string) ($taxonomy->slug ?? '');

        if ($slug !== (string) $taxonomy->slug) {
            if (Taxonomy::query()->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages([
                    'slug' => ['Taxonomy slug already exists.'],
                ]);
            }
        }

        $taxonomy->fill($this->mergeEntry($taxonomy, $payload, $slug));
        $taxonomy->save();

        return $this->toPayload($taxonomy);
    }

    public function delete(string $taxonomyId): void
    {
        $taxonomy = Taxonomy::query()->where('_id', $taxonomyId)->first();
        if (! $taxonomy) {
            abort(404, 'Taxonomy not found.');
        }

        TaxonomyTerm::query()->where('taxonomy_id', (string) $taxonomy->_id)->delete();
        $taxonomy->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildEntry(array $payload, string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => trim((string) ($payload['name'] ?? '')),
            'applies_to' => $this->normalizeAppliesTo($payload['applies_to'] ?? []),
            'icon' => $this->normalizeOptionalString($payload['icon'] ?? null),
            'color' => $this->normalizeOptionalString($payload['color'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeEntry(Taxonomy $taxonomy, array $payload, string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => array_key_exists('name', $payload)
                ? trim((string) $payload['name'])
                : (string) ($taxonomy->name ?? ''),
            'applies_to' => array_key_exists('applies_to', $payload)
                ? $this->normalizeAppliesTo($payload['applies_to'] ?? [])
                : $this->normalizeAppliesTo($taxonomy->applies_to ?? []),
            'icon' => array_key_exists('icon', $payload)
                ? $this->normalizeOptionalString($payload['icon'] ?? null)
                : $this->normalizeOptionalString($taxonomy->icon ?? null),
            'color' => array_key_exists('color', $payload)
                ? $this->normalizeOptionalString($payload['color'] ?? null)
                : $this->normalizeOptionalString($taxonomy->color ?? null),
        ];
    }

    private function normalizeSlug(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAppliesTo(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = array_map(static fn ($value): string => trim((string) $value), $raw);

        return array_values(array_filter(array_unique($normalized), static fn (string $value): bool => $value !== ''));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(Taxonomy $taxonomy): array
    {
        return [
            'id' => (string) $taxonomy->_id,
            'slug' => (string) ($taxonomy->slug ?? ''),
            'name' => (string) ($taxonomy->name ?? ''),
            'applies_to' => array_values(array_filter(
                is_array($taxonomy->applies_to ?? null) ? $taxonomy->applies_to : [],
                static fn ($value): bool => is_string($value) && $value !== ''
            )),
            'icon' => $this->normalizeOptionalString($taxonomy->icon ?? null),
            'color' => $this->normalizeOptionalString($taxonomy->color ?? null),
        ];
    }
}
