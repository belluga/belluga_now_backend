<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Models\Tenants\EventType;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use MongoDB\Driver\Exception\BulkWriteException;

class EventTypeRegistryManagementService
{
    public function __construct(
        private readonly EventTypeRegistryService $registryService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        $entry = $this->buildEntry($payload);

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

        return $this->registryService->toPayload($model);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(string $eventTypeId, array $payload): array
    {
        $model = $this->findModelOrFail($eventTypeId);

        $entry = $this->mergeEntry($model, $payload);

        $slugChanged = $entry['slug'] !== (string) ($model->slug ?? '');
        if ($slugChanged && EventType::query()->where('slug', $entry['slug'])->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['Event type slug already exists.'],
            ]);
        }

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

        $snapshot = $this->registryService->toPayload($model);
        $eventTypeId = (string) $snapshot['id'];
        Event::query()
            ->where('type.id', $eventTypeId)
            ->update(['type' => $snapshot]);

        EventOccurrence::query()
            ->where('type.id', $eventTypeId)
            ->update([
                'type' => $snapshot,
                'updated_from_event_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return $snapshot;
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildEntry(array $payload): array
    {
        return [
            'name' => trim((string) ($payload['name'] ?? '')),
            'slug' => trim((string) ($payload['slug'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'icon' => $this->normalizeNullableString($payload['icon'] ?? null),
            'color' => $this->normalizeNullableString($payload['color'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mergeEntry(EventType $existing, array $payload): array
    {
        return [
            'name' => array_key_exists('name', $payload)
                ? trim((string) $payload['name'])
                : trim((string) ($existing->name ?? '')),
            'slug' => array_key_exists('slug', $payload)
                ? trim((string) $payload['slug'])
                : trim((string) ($existing->slug ?? '')),
            'description' => array_key_exists('description', $payload)
                ? trim((string) $payload['description'])
                : trim((string) ($existing->description ?? '')),
            'icon' => array_key_exists('icon', $payload)
                ? $this->normalizeNullableString($payload['icon'])
                : $this->normalizeNullableString($existing->icon ?? null),
            'color' => array_key_exists('color', $payload)
                ? $this->normalizeNullableString($payload['color'])
                : $this->normalizeNullableString($existing->color ?? null),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
