<?php

declare(strict_types=1);

namespace App\Application\MapPois;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Application\StaticAssets\StaticProfileTypeRegistryService;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\MapPoi;
use App\Models\Tenants\StaticAsset;
use App\Models\Tenants\TenantSettings;
use Belluga\Events\Models\Tenants\Event;
use Illuminate\Support\Carbon;

class MapPoiProjectionService
{
    public function __construct(
        private readonly AccountProfileRegistryService $accountProfileRegistryService,
        private readonly StaticProfileTypeRegistryService $staticProfileTypeRegistryService,
    ) {
    }

    public function deleteByRef(string $refType, string $refId): void
    {
        MapPoi::query()
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->delete();
    }

    public function upsertFromAccountProfile(AccountProfile $profile): void
    {
        if (! $profile->profile_type) {
            $this->deleteByRef('account_profile', (string) $profile->_id);
            return;
        }

        if (! $this->accountProfileRegistryService->isPoiEnabled($profile->profile_type)) {
            $this->deleteByRef('account_profile', (string) $profile->_id);
            return;
        }

        $location = $this->normalizeLocation($profile->location ?? null);
        if (! $location) {
            $this->deleteByRef('account_profile', (string) $profile->_id);
            return;
        }

        $payload = [
            'ref_type' => 'account_profile',
            'ref_id' => (string) $profile->_id,
            'ref_slug' => $profile->slug ?? null,
            'ref_path' => $this->buildRefPath('account_profile', $profile->slug ?? null),
            'name' => (string) ($profile->display_name ?? ''),
            'subtitle' => $profile->bio ?? null,
            'category' => $profile->profile_type,
            'tags' => [],
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($profile->taxonomy_terms ?? []),
            'taxonomy_terms_flat' => $this->flattenTaxonomyTerms($profile->taxonomy_terms ?? []),
            'location' => $location,
            'priority' => 40,
            'is_active' => (bool) ($profile->is_active ?? false),
            'active_window_start_at' => null,
            'active_window_end_at' => null,
            'time_start' => null,
            'time_end' => null,
            'avatar_url' => $profile->avatar_url ?? null,
            'cover_url' => $profile->cover_url ?? null,
            'badge' => null,
            'exact_key' => $this->exactKey($location),
        ];

        $this->upsert($payload);
    }

    public function upsertFromStaticAsset(StaticAsset $asset): void
    {
        if (! $asset->profile_type) {
            $this->deleteByRef('static', (string) $asset->_id);
            return;
        }

        if (! $this->staticProfileTypeRegistryService->isPoiEnabled($asset->profile_type)) {
            $this->deleteByRef('static', (string) $asset->_id);
            return;
        }

        $location = $this->normalizeLocation($asset->location ?? null);
        if (! $location) {
            $this->deleteByRef('static', (string) $asset->_id);
            return;
        }

        $mapCategory = $this->staticProfileTypeRegistryService->resolveMapCategory(
            (string) $asset->profile_type
        );
        $payload = [
            'ref_type' => 'static',
            'ref_id' => (string) $asset->_id,
            'ref_slug' => $asset->slug ?? null,
            'ref_path' => $this->buildRefPath('static', $asset->slug ?? null),
            'name' => (string) ($asset->display_name ?? ''),
            'subtitle' => $asset->bio ?? null,
            'category' => $mapCategory,
            'tags' => $this->normalizeStringArray($asset->tags ?? []),
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($asset->taxonomy_terms ?? []),
            'taxonomy_terms_flat' => $this->flattenTaxonomyTerms($asset->taxonomy_terms ?? []),
            'location' => $location,
            'priority' => 20,
            'is_active' => (bool) ($asset->is_active ?? false),
            'active_window_start_at' => null,
            'active_window_end_at' => null,
            'time_start' => null,
            'time_end' => null,
            'avatar_url' => $asset->avatar_url ?? null,
            'cover_url' => $asset->cover_url ?? null,
            'badge' => null,
            'exact_key' => $this->exactKey($location),
        ];

        $this->upsert($payload);
    }

    public function upsertFromEvent(Event $event): void
    {
        $location = $this->normalizeLocation($event->geo_location ?? null);
        if (! $location) {
            $this->deleteByRef('event', (string) $event->_id);
            return;
        }

        $publication = is_array($event->publication ?? null)
            ? $event->publication
            : (array) ($event->publication ?? []);

        $status = (string) ($publication['status'] ?? 'published');
        $publishAt = $publication['publish_at'] ?? null;
        $publishAt = $publishAt ? Carbon::parse($publishAt) : null;

        $now = Carbon::now();
        $isActive = $status === 'published'
            && ($publishAt === null || $publishAt->lessThanOrEqualTo($now));

        $start = $event->date_time_start ? Carbon::parse($event->date_time_start) : null;
        $end = $event->date_time_end ? Carbon::parse($event->date_time_end) : null;

        if ($start && ! $end) {
            $end = $start->copy()->addHours($this->resolveDefaultEventDurationHours());
        }

        $categories = $this->normalizeStringArray($event->categories ?? []);
        $thumb = is_array($event->thumb ?? null) ? $event->thumb : (array) ($event->thumb ?? []);
        $thumbData = is_array($thumb['data'] ?? null) ? $thumb['data'] : [];
        $coverUrl = $thumbData['url'] ?? null;
        $payload = [
            'ref_type' => 'event',
            'ref_id' => (string) $event->_id,
            'ref_slug' => $event->slug ?? null,
            'ref_path' => $this->buildRefPath('event', $event->slug ?? null),
            'name' => (string) ($event->title ?? ''),
            'subtitle' => $event->venue['display_name'] ?? null,
            'category' => $categories[0] ?? 'event',
            'tags' => $this->normalizeStringArray($event->tags ?? []),
            'taxonomy_terms' => $this->normalizeTaxonomyTerms($event->taxonomy_terms ?? []),
            'taxonomy_terms_flat' => $this->flattenTaxonomyTerms($event->taxonomy_terms ?? []),
            'location' => $location,
            'priority' => $this->resolveEventPriority($start, $end),
            'is_active' => $isActive,
            'active_window_start_at' => $start,
            'active_window_end_at' => $end,
            'time_start' => $start,
            'time_end' => $end,
            'avatar_url' => null,
            'cover_url' => $coverUrl,
            'badge' => null,
            'exact_key' => $this->exactKey($location),
        ];

        $this->upsert($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsert(array $payload): void
    {
        MapPoi::query()->updateOrCreate(
            [
                'ref_type' => $payload['ref_type'],
                'ref_id' => $payload['ref_id'],
            ],
            $payload
        );
    }

    /**
     * @param mixed $location
     * @return array<string, mixed>|null
     */
    private function normalizeLocation(mixed $location): ?array
    {
        if ($location instanceof \MongoDB\Model\BSONDocument || $location instanceof \MongoDB\Model\BSONArray) {
            $location = $location->getArrayCopy();
        } elseif (is_object($location) && method_exists($location, 'toArray')) {
            $location = $location->toArray();
        }

        if (! is_array($location)) {
            return null;
        }

        $coordinates = $location['coordinates'] ?? null;
        if ($coordinates instanceof \MongoDB\Model\BSONDocument || $coordinates instanceof \MongoDB\Model\BSONArray) {
            $coordinates = $coordinates->getArrayCopy();
        } elseif (is_object($coordinates) && method_exists($coordinates, 'toArray')) {
            $coordinates = $coordinates->toArray();
        }

        if (! is_array($coordinates) || count($coordinates) < 2) {
            return null;
        }

        $lng = round((float) $coordinates[0], 5);
        $lat = round((float) $coordinates[1], 5);

        return [
            'type' => 'Point',
            'coordinates' => [$lng, $lat],
        ];
    }

    /**
     * @param array<string, mixed> $location
     */
    private function exactKey(array $location): string
    {
        $coordinates = $location['coordinates'] ?? [0.0, 0.0];
        $lng = number_format((float) ($coordinates[0] ?? 0.0), 5, '.', '');
        $lat = number_format((float) ($coordinates[1] ?? 0.0), 5, '.', '');

        return $lat . ',' . $lng;
    }

    /**
     * @param array<int, mixed> $terms
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

    /**
     * @param array<int, mixed> $terms
     * @return array<int, string>
     */
    private function flattenTaxonomyTerms(array $terms): array
    {
        $flattened = [];

        foreach ($terms as $term) {
            if (! is_array($term)) {
                continue;
            }
            $type = trim((string) ($term['type'] ?? ''));
            $value = trim((string) ($term['value'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }
            $flattened[] = $type . ':' . $value;
        }

        return array_values(array_unique($flattened));
    }

    /**
     * @param array<int, mixed> $values
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

    private function resolveEventPriority(?Carbon $start, ?Carbon $end): int
    {
        if (! $start) {
            return 60;
        }

        $now = Carbon::now();
        $end = $end ?? $start->copy()->addHours($this->resolveDefaultEventDurationHours());

        if ($now->between($start, $end)) {
            return 80;
        }

        return 60;
    }

    private function resolveDefaultEventDurationHours(): int
    {
        $settings = TenantSettings::current();
        $events = $settings?->getAttribute('events') ?? [];
        $events = is_array($events) ? $events : [];
        $default = (int) ($events['default_duration_hours'] ?? 3);

        return $default > 0 ? $default : 3;
    }

    private function buildRefPath(string $refType, ?string $slug): ?string
    {
        if (! $slug) {
            return null;
        }

        return '/' . $refType . '/' . $slug;
    }
}
