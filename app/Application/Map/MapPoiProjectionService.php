<?php

declare(strict_types=1);

namespace App\Application\Map;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\Event;
use App\Models\Tenants\MapPoi;
use App\Models\Tenants\StaticAsset;
use Illuminate\Support\Carbon;

class MapPoiProjectionService
{
    private const PRIORITY_SPONSORED = 100;
    private const PRIORITY_LIVE_EVENT = 80;
    private const PRIORITY_UPCOMING_EVENT = 60;
    private const PRIORITY_ACCOUNT_PROFILE = 40;
    private const PRIORITY_STATIC_ASSET = 20;

    private const COORD_PRECISION = 6;

    public function __construct(
        private readonly AccountProfileRegistryService $registryService
    ) {
    }

    public function upsertForAccountProfile(AccountProfile $profile): void
    {
        if (! $profile->profile_type || ! $this->registryService->isPoiEnabled($profile->profile_type)) {
            $this->removeByReference('account_profile', (string) $profile->_id);

            return;
        }

        $location = $this->normalizeLocation($profile->location ?? null);
        if (! $location) {
            $this->removeByReference('account_profile', (string) $profile->_id);

            return;
        }

        $category = $this->resolveProfileCategory($profile);
        if (! $category) {
            $this->removeByReference('account_profile', (string) $profile->_id);

            return;
        }

        $payload = [
            'tenant_id' => $this->resolveTenantId(),
            'ref_type' => 'account_profile',
            'ref_id' => (string) $profile->_id,
            'name' => $profile->display_name,
            'subtitle' => null,
            'category' => $category,
            'tags' => [],
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($profile->taxonomy_terms ?? []),
            'priority' => self::PRIORITY_ACCOUNT_PROFILE,
            'location' => $location['location'],
            'exact_key' => $location['exact_key'],
            'time_anchor_at' => null,
            'media' => null,
            'badge' => null,
            'is_active' => (bool) ($profile->is_active ?? true),
        ];

        $this->upsert('account_profile', (string) $profile->_id, $payload);
    }

    public function upsertForEvent(Event $event): void
    {
        $location = $this->normalizeLocation($event->geo_location ?? null);
        if (! $location) {
            $this->removeByReference('event', (string) $event->_id);

            return;
        }

        $category = $this->resolveEventCategory($event);
        $tags = $this->normalizeStringArray($event->tags ?? []);
        $taxonomy = $this->resolveEventTaxonomy($event);

        $payload = [
            'tenant_id' => $this->resolveTenantId(),
            'ref_type' => 'event',
            'ref_id' => (string) $event->_id,
            'name' => $event->title,
            'subtitle' => $this->resolveVenueSubtitle($event),
            'category' => $category,
            'tags' => $tags,
            'taxonomy_terms' => $taxonomy,
            'priority' => $this->resolveEventPriority($event),
            'location' => $location['location'],
            'exact_key' => $location['exact_key'],
            'time_anchor_at' => $event->date_time_start,
            'media' => $event->thumb ?? null,
            'badge' => null,
            'is_active' => (bool) ($event->is_active ?? true),
        ];

        $this->upsert('event', (string) $event->_id, $payload);
    }

    public function upsertForStaticAsset(StaticAsset $asset): void
    {
        $location = $this->normalizeLocation($asset->location ?? null);
        if (! $location) {
            $this->removeByReference('static', (string) $asset->_id);

            return;
        }

        $payload = [
            'tenant_id' => $this->resolveTenantId(),
            'ref_type' => 'static',
            'ref_id' => (string) $asset->_id,
            'name' => $asset->name,
            'subtitle' => $asset->description ?? null,
            'category' => (string) $asset->category,
            'tags' => $this->normalizeStringArray($asset->tags ?? []),
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($asset->taxonomy_terms ?? []),
            'priority' => $asset->priority ?? self::PRIORITY_STATIC_ASSET,
            'location' => $location['location'],
            'exact_key' => $location['exact_key'],
            'time_anchor_at' => null,
            'media' => $asset->media ?? null,
            'badge' => $asset->badge ?? null,
            'is_active' => (bool) ($asset->is_active ?? true),
        ];

        $this->upsert('static', (string) $asset->_id, $payload);
    }

    public function removeByReference(string $refType, string $refId): void
    {
        $poi = MapPoi::withTrashed()
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->first();

        if (! $poi) {
            return;
        }

        if (! $poi->trashed()) {
            $poi->delete();

            return;
        }

        $poi->forceDelete();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsert(string $refType, string $refId, array $payload): void
    {
        $poi = MapPoi::withTrashed()
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->first();

        if (! $poi) {
            MapPoi::create($payload);

            return;
        }

        $poi->fill($payload);
        $poi->deleted_at = null;
        $poi->save();
    }

    /**
     * @param mixed $location
     * @return array{location: array<string, mixed>, exact_key: string}|null
     */
    private function normalizeLocation(mixed $location): ?array
    {
        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        $lng = round((float) $coordinates[0], self::COORD_PRECISION);
        $lat = round((float) $coordinates[1], self::COORD_PRECISION);

        return [
            'location' => [
                'type' => 'Point',
                'coordinates' => [$lng, $lat],
            ],
            'exact_key' => $this->buildExactKey($lat, $lng),
        ];
    }

    private function buildExactKey(float $lat, float $lng): string
    {
        return number_format($lat, self::COORD_PRECISION, '.', '')
            . ','
            . number_format($lng, self::COORD_PRECISION, '.', '');
    }

    private function resolveTenantId(): ?string
    {
        $tenant = Tenant::current();

        return $tenant ? (string) $tenant->_id : null;
    }

    private function resolveProfileCategory(AccountProfile $profile): ?string
    {
        $definition = $this->registryService->typeDefinition($profile->profile_type);
        $category = is_array($definition) ? ($definition['poi_category'] ?? null) : null;

        if (is_string($category) && $category !== '') {
            return $category;
        }

        return match ($profile->profile_type) {
            'venue', 'restaurant' => 'restaurant',
            default => null,
        };
    }

    private function resolveEventCategory(Event $event): string
    {
        $categories = $this->normalizeStringArray($event->categories ?? []);

        return $categories[0] ?? 'event';
    }

    private function resolveEventPriority(Event $event): int
    {
        $startAt = $this->normalizeDate($event->date_time_start ?? null);
        $endAt = $this->normalizeDate($event->date_time_end ?? null);
        $now = Carbon::now();

        if ($startAt && ! $endAt) {
            $endAt = $startAt->copy()->addHours(3);
        }

        if ($startAt && $endAt && $now->between($startAt, $endAt)) {
            return self::PRIORITY_LIVE_EVENT;
        }

        return self::PRIORITY_UPCOMING_EVENT;
    }

    private function resolveVenueSubtitle(Event $event): ?string
    {
        $venue = $event->venue ?? null;
        if (! is_array($venue)) {
            return null;
        }

        $display = $venue['display_name'] ?? $venue['name'] ?? null;
        if (! is_string($display) || $display === '') {
            return null;
        }

        return $display;
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    private function resolveEventTaxonomy(Event $event): array
    {
        $terms = [];

        $terms = array_merge($terms, $this->normalizeTaxonomyTerms($event->taxonomy_terms ?? []));

        $venue = $event->venue ?? null;
        if (is_array($venue)) {
            $terms = array_merge($terms, $this->normalizeTaxonomyTerms($venue['taxonomy_terms'] ?? []));
        }

        $artists = $event->artists ?? null;
        if (is_array($artists)) {
            foreach ($artists as $artist) {
                if (! is_array($artist)) {
                    continue;
                }

                $terms = array_merge($terms, $this->normalizeTaxonomyTerms($artist['taxonomy_terms'] ?? []));
            }
        }

        return $this->uniqueTaxonomyTerms($terms);
    }

    /**
     * @param mixed $items
     * @return array<int, string>
     */
    private function normalizeStringArray(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($item): ?string {
            if (! is_string($item) || trim($item) === '') {
                return null;
            }

            return trim($item);
        }, $items)));
    }

    /**
     * @param mixed $items
     * @return array<int, array{type: string, value: string}>
     */
    private function normalizeTaxonomyTerms(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = isset($item['type']) ? trim((string) $item['type']) : '';
            $value = isset($item['value']) ? trim((string) $item['value']) : '';

            if ($type === '' || $value === '') {
                continue;
            }

            $normalized[] = ['type' => $type, 'value' => $value];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{type: string, value: string}> $terms
     * @return array<int, array{type: string, value: string}>
     */
    private function uniqueTaxonomyTerms(array $terms): array
    {
        $seen = [];
        $unique = [];

        foreach ($terms as $term) {
            $key = strtolower($term['type']) . ':' . strtolower($term['value']);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $term;
        }

        return $unique;
    }

    private function normalizeDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        return null;
    }
}
