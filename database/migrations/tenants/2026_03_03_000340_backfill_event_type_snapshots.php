<?php

use App\Models\Tenants\EventType;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_types') || ! Schema::hasTable('events')) {
            return;
        }

        $events = Event::query()->get(['_id', 'type']);
        foreach ($events as $event) {
            $snapshot = $this->resolveSnapshot($event->type ?? null);
            if ($snapshot === null) {
                continue;
            }

            $eventId = (string) $event->_id;
            Event::query()->where('_id', $event->_id)->update([
                'type' => $snapshot,
                'updated_at' => Carbon::now(),
            ]);

            if (Schema::hasTable('event_occurrences')) {
                EventOccurrence::query()->where('event_id', $eventId)->update([
                    'type' => $snapshot,
                    'updated_from_event_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // no-op: backfill only
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSnapshot(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $id = trim((string) ($value['id'] ?? ''));
        if ($id !== '' && preg_match('/^[a-f0-9]{24}$/i', $id) === 1) {
            $existingById = EventType::query()->where('_id', $id)->first();
            if ($existingById) {
                return $this->toSnapshot($existingById);
            }
        }

        $name = trim((string) ($value['name'] ?? ''));
        $slug = trim((string) ($value['slug'] ?? ''));

        if ($slug === '') {
            $slug = Str::slug($name);
        }
        if ($slug === '') {
            return null;
        }

        if ($name === '') {
            $name = Str::title(str_replace(['-', '_'], ' ', $slug));
        }

        $description = trim((string) ($value['description'] ?? ''));
        if (mb_strlen($description) < 10) {
            $description = 'Tipo de evento: '.$name;
        }

        $model = EventType::query()->where('slug', $slug)->first();
        if (! $model) {
            $model = EventType::query()->create([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'icon' => $this->normalizeNullableString($value['icon'] ?? null),
                'color' => $this->normalizeNullableString($value['color'] ?? null),
            ]);

            return $this->toSnapshot($model);
        }

        $nextDescription = trim((string) ($model->description ?? ''));
        if (mb_strlen($nextDescription) < 10) {
            $nextDescription = $description;
        }

        $nextName = trim((string) ($model->name ?? ''));
        if ($nextName === '') {
            $nextName = $name;
        }

        $model->fill([
            'name' => $nextName,
            'description' => $nextDescription,
            'icon' => $this->normalizeNullableString($model->icon ?? ($value['icon'] ?? null)),
            'color' => $this->normalizeNullableString($model->color ?? ($value['color'] ?? null)),
        ]);
        $model->save();

        return $this->toSnapshot($model);
    }

    /**
     * @return array<string, mixed>
     */
    private function toSnapshot(EventType $model): array
    {
        return [
            'id' => (string) $model->_id,
            'name' => trim((string) ($model->name ?? '')),
            'slug' => trim((string) ($model->slug ?? '')),
            'description' => trim((string) ($model->description ?? '')),
            'icon' => $this->normalizeNullableString($model->icon ?? null),
            'color' => $this->normalizeNullableString($model->color ?? null),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
};
