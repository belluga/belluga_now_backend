<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Application\Shared\MapPois\PoiVisualNormalizer;
use App\Models\Tenants\EventType;
use App\Models\Tenants\Taxonomy;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\MapPois\Jobs\UpsertMapPoiFromEventJob;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class EventTypeRegistryManagementService
{
    public function __construct(
        private readonly EventTypeRegistryService $registryService,
        private readonly PoiVisualNormalizer $poiVisualNormalizer,
        private readonly EventTypeMediaService $mediaService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(Request $request, array $payload): array
    {
        $entry = $this->buildEntry($payload);
        $this->ensureTypeAssetRequirements(
            $entry['visual'] ?? null,
            $request,
            null,
            false,
        );

        if (EventType::query()->where('slug', $entry['slug'])->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['Event type slug already exists.'],
            ]);
        }

        try {
            $model = EventType::query()->create($entry);
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'slug' => ['Event type slug already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'event_type' => ['Something went wrong when trying to create the event type.'],
            ]);
        }

        $this->mediaService->applyUploads($request, $model);
        $model = $model->fresh() ?? $model;

        return $this->registryService->toPayload($model, $request->getSchemeAndHttpHost());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(Request $request, string $eventTypeId, array $payload): array
    {
        $model = $this->findModelOrFail($eventTypeId);

        $entry = $this->mergeEntry($model, $payload);
        $slugChanged = $entry['slug'] !== (string) ($model->slug ?? '');
        if ($slugChanged && EventType::query()->where('slug', $entry['slug'])->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['Event type slug already exists.'],
            ]);
        }

        $existingTypeAssetUrl = $this->normalizeNullableString($model->type_asset_url ?? null);
        $this->ensureTypeAssetRequirements(
            $entry['visual'] ?? null,
            $request,
            $existingTypeAssetUrl,
            $request->boolean('remove_type_asset'),
        );

        try {
            $model->fill($entry);
            $model->save();
        } catch (BulkWriteException $exception) {
            if (str_contains($exception->getMessage(), 'E11000')) {
                throw ValidationException::withMessages([
                    'slug' => ['Event type slug already exists.'],
                ]);
            }

            throw ValidationException::withMessages([
                'event_type' => ['Something went wrong when trying to update the event type.'],
            ]);
        }

        $this->mediaService->applyUploads($request, $model);
        $model = $model->fresh() ?? $model;

        $snapshot = $this->registryService->toPayload($model);
        $eventTypeId = (string) $snapshot['id'];
        $now = Carbon::now();
        $eventIds = Event::query()
            ->where('type.id', $eventTypeId)
            ->get(['_id'])
            ->map(static fn (Event $event): string => (string) $event->getKey())
            ->all();

        Event::query()
            ->where('type.id', $eventTypeId)
            ->update([
                'type' => $snapshot,
                'updated_at' => $now,
            ]);

        EventOccurrence::query()
            ->where('type.id', $eventTypeId)
            ->update([
                'type' => $snapshot,
                'updated_from_event_at' => $now,
                'updated_at' => $now,
            ]);

        $forcedCheckpoint = $this->toCheckpoint($now);
        $jobCheckpoint = $forcedCheckpoint > 0 ? $forcedCheckpoint : null;
        foreach ($eventIds as $eventId) {
            UpsertMapPoiFromEventJob::dispatch($eventId, $jobCheckpoint);
        }

        return $this->registryService->toPayload($model, $request->getSchemeAndHttpHost());
    }

    public function delete(string $eventTypeId): void
    {
        $model = $this->findModelOrFail($eventTypeId);
        $resolvedId = (string) $model->_id;

        if (Event::query()->where('type.id', $resolvedId)->exists()) {
            throw ValidationException::withMessages([
                'event_type' => ['Event type cannot be deleted while referenced by events.'],
            ]);
        }

        $model->delete();
    }

    private function findModelOrFail(string $eventTypeId): EventType
    {
        $id = trim($eventTypeId);
        $model = EventType::query()->where('_id', $id)->first();
        if (! $model) {
            abort(404, 'Event type not found.');
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildEntry(array $payload): array
    {
        $visual = $this->resolveIncomingVisual($payload);
        $legacy = $this->legacyFieldsFromVisual($visual);

        return [
            'name' => trim((string) ($payload['name'] ?? '')),
            'slug' => trim((string) ($payload['slug'] ?? '')),
            'description' => $this->normalizeNullableString($payload['description'] ?? null),
            'allowed_taxonomies' => $this->normalizeAllowedEventTaxonomies($payload['allowed_taxonomies'] ?? []),
            'visual' => $visual,
            'poi_visual' => $visual,
            'icon' => $legacy['icon'],
            'color' => $legacy['color'],
            'icon_color' => $legacy['icon_color'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeEntry(EventType $existing, array $payload): array
    {
        $visual = $this->resolveIncomingVisual(
            $payload,
            $existing->visual ?? $existing->poi_visual ?? [
                'mode' => 'icon',
                'icon' => $existing->icon,
                'color' => $existing->color,
                'icon_color' => $existing->icon_color,
            ],
        );
        $legacy = $this->legacyFieldsFromVisual($visual);

        return [
            'name' => array_key_exists('name', $payload)
                ? trim((string) $payload['name'])
                : trim((string) ($existing->name ?? '')),
            'slug' => array_key_exists('slug', $payload)
                ? trim((string) $payload['slug'])
                : trim((string) ($existing->slug ?? '')),
            'description' => array_key_exists('description', $payload)
                ? $this->normalizeNullableString($payload['description'])
                : $this->normalizeNullableString($existing->description ?? null),
            'allowed_taxonomies' => array_key_exists('allowed_taxonomies', $payload)
                ? $this->normalizeAllowedEventTaxonomies($payload['allowed_taxonomies'])
                : $this->normalizeAllowedEventTaxonomies($existing->allowed_taxonomies ?? []),
            'visual' => $visual,
            'poi_visual' => $visual,
            'icon' => $legacy['icon'],
            'color' => $legacy['color'],
            'icon_color' => $legacy['icon_color'],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveIncomingVisual(array $payload, mixed $fallback = null): ?array
    {
        if (array_key_exists('visual', $payload)) {
            return $this->poiVisualNormalizer->normalize($payload['visual'] ?? null);
        }

        if (array_key_exists('poi_visual', $payload)) {
            return $this->poiVisualNormalizer->normalize($payload['poi_visual'] ?? null);
        }

        if (
            array_key_exists('icon', $payload)
            || array_key_exists('color', $payload)
            || array_key_exists('icon_color', $payload)
        ) {
            $legacyFallback = $this->legacyFieldsFromVisual(
                $this->poiVisualNormalizer->normalize($fallback)
            );

            return $this->poiVisualNormalizer->normalize([
                'mode' => 'icon',
                'icon' => array_key_exists('icon', $payload)
                    ? ($payload['icon'] ?? null)
                    : $legacyFallback['icon'],
                'color' => array_key_exists('color', $payload)
                    ? ($payload['color'] ?? null)
                    : $legacyFallback['color'],
                'icon_color' => array_key_exists('icon_color', $payload)
                    ? ($payload['icon_color'] ?? null)
                    : $legacyFallback['icon_color'],
            ]);
        }

        return $this->poiVisualNormalizer->normalize($fallback);
    }

    /**
     * @param  array<string, string>|null  $visual
     * @return array{icon: ?string, color: ?string, icon_color: ?string}
     */
    private function legacyFieldsFromVisual(?array $visual): array
    {
        if (! is_array($visual) || ($visual['mode'] ?? null) !== 'icon') {
            return [
                'icon' => null,
                'color' => null,
                'icon_color' => null,
            ];
        }

        return [
            'icon' => $this->normalizeNullableString($visual['icon'] ?? null),
            'color' => $this->normalizeNullableString($visual['color'] ?? null),
            'icon_color' => $this->normalizeNullableString($visual['icon_color'] ?? null),
        ];
    }

    private function ensureTypeAssetRequirements(
        ?array $visual,
        Request $request,
        ?string $existingTypeAssetUrl,
        bool $removeTypeAsset,
    ): void {
        if (! is_array($visual)) {
            return;
        }

        if (($visual['mode'] ?? null) !== 'image' || ($visual['image_source'] ?? null) !== 'type_asset') {
            return;
        }

        $hasUpload = $request->hasFile('type_asset');
        $hasExisting = $existingTypeAssetUrl !== null && ! $removeTypeAsset;
        if ($hasUpload || $hasExisting) {
            return;
        }

        throw ValidationException::withMessages([
            'type_asset' => ['Type asset image is required when image_source is type_asset.'],
        ]);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];

        return collect($items)
            ->map(fn ($item): string => strtolower(trim((string) $item)))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAllowedEventTaxonomies(mixed $value): array
    {
        $slugs = $this->normalizeStringList($value);
        if ($slugs === []) {
            return [];
        }

        $taxonomies = Taxonomy::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy(fn (Taxonomy $taxonomy): string => (string) $taxonomy->slug);

        $missing = array_values(array_diff($slugs, array_keys($taxonomies->all())));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'allowed_taxonomies' => ['Some allowed taxonomies are not registered for this tenant.'],
            ]);
        }

        foreach ($slugs as $slug) {
            $taxonomy = $taxonomies->get($slug);
            $appliesTo = is_array($taxonomy?->applies_to ?? null) ? $taxonomy->applies_to : [];
            if (! in_array('event', $appliesTo, true)) {
                throw ValidationException::withMessages([
                    'allowed_taxonomies' => ['Some allowed taxonomies are not applicable to events.'],
                ]);
            }
        }

        return $slugs;
    }

    private function toCheckpoint(mixed $value): int
    {
        if ($value instanceof Carbon) {
            return (int) $value->valueOf();
        }

        if ($value instanceof \DateTimeInterface) {
            return (int) Carbon::instance($value)->valueOf();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return (int) Carbon::parse($value)->valueOf();
            } catch (\Exception) {
                return 0;
            }
        }

        return 0;
    }
}
