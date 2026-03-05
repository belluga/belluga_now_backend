<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Models\Tenants\EventType;

class EventTypeRegistryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function registry(): array
    {
        return EventType::query()
            ->orderBy('name')
            ->get()
            ->map(fn (EventType $type): array => $this->toPayload($type))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $normalizedId = trim($id);
        if ($normalizedId === '') {
            return null;
        }

        $model = EventType::query()->where('_id', $normalizedId)->first();

        return $model ? $this->toPayload($model) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $normalizedSlug = trim($slug);
        if ($normalizedSlug === '') {
            return null;
        }

        $model = EventType::query()->where('slug', $normalizedSlug)->first();

        return $model ? $this->toPayload($model) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(EventType $model): array
    {
        $description = trim((string) ($model->description ?? ''));

        return [
            'id' => (string) $model->_id,
            'name' => trim((string) ($model->name ?? '')),
            'slug' => trim((string) ($model->slug ?? '')),
            'description' => $description,
            'icon' => $this->normalizeNullableString($model->icon ?? null),
            'color' => $this->normalizeNullableString($model->color ?? null),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
