<?php

declare(strict_types=1);

use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    private const OCCURRENCE_INDEX_UNIQUE = 'event_id_1_occurrence_index_1';

    public function up(): void
    {
        $this->dropIndexIfExists('event_occurrences', self::OCCURRENCE_INDEX_UNIQUE);

        Event::withTrashed()
            ->orderBy('_id')
            ->cursor()
            ->each(function (Event $event): void {
                $eventId = isset($event->_id) ? (string) $event->_id : '';
                if ($eventId === '') {
                    return;
                }

                $occurrences = EventOccurrence::withTrashed()
                    ->where('event_id', $eventId)
                    ->orderBy('starts_at')
                    ->orderBy('_id')
                    ->get();

                $occurrenceRefs = [];
                foreach ($occurrences->values() as $order => $occurrence) {
                    $occurrenceRefs[] = [
                        'occurrence_id' => isset($occurrence->_id) ? (string) $occurrence->_id : null,
                        'occurrence_slug' => $this->normalizeOptionalString($occurrence->occurrence_slug ?? null),
                        'order' => $order,
                    ];
                }

                $event->forceFill([
                    'occurrence_refs' => $occurrenceRefs,
                ])->saveQuietly();
            });

        DB::connection('tenant')
            ->getCollection('event_occurrences')
            ->updateMany([], ['$unset' => ['occurrence_index' => '']]);
    }

    public function down(): void
    {
        Event::withTrashed()
            ->orderBy('_id')
            ->cursor()
            ->each(function (Event $event): void {
                $eventId = isset($event->_id) ? (string) $event->_id : '';
                if ($eventId === '') {
                    return;
                }

                $occurrences = EventOccurrence::withTrashed()
                    ->where('event_id', $eventId)
                    ->orderBy('starts_at')
                    ->orderBy('_id')
                    ->get()
                    ->values()
                    ->all();

                $occurrenceRefs = $this->normalizeOccurrenceRefs($event->occurrence_refs ?? []);
                if ($occurrenceRefs !== []) {
                    $orderById = [];
                    $orderBySlug = [];
                    foreach ($occurrenceRefs as $ref) {
                        $order = (int) ($ref['order'] ?? count($orderById));
                        $occurrenceId = $this->normalizeOptionalString($ref['occurrence_id'] ?? null);
                        $occurrenceSlug = $this->normalizeOptionalString($ref['occurrence_slug'] ?? null);
                        if ($occurrenceId !== null) {
                            $orderById[$occurrenceId] = $order;
                        }
                        if ($occurrenceSlug !== null) {
                            $orderBySlug[$occurrenceSlug] = $order;
                        }
                    }

                    usort($occurrences, function (EventOccurrence $left, EventOccurrence $right) use ($orderById, $orderBySlug): int {
                        $leftOrder = $this->resolveOccurrenceOrder($left, $orderById, $orderBySlug);
                        $rightOrder = $this->resolveOccurrenceOrder($right, $orderById, $orderBySlug);
                        if ($leftOrder !== $rightOrder) {
                            return $leftOrder <=> $rightOrder;
                        }

                        return strcmp((string) ($left->_id ?? ''), (string) ($right->_id ?? ''));
                    });
                }

                foreach ($occurrences as $order => $occurrence) {
                    $occurrence->occurrence_index = $order;
                    $occurrence->saveQuietly();
                }

                $event->unset('occurrence_refs');
                $event->saveQuietly();
            });

        Schema::table('event_occurrences', function (Blueprint $collection): void {
            $collection->unique(['event_id' => 1, 'occurrence_index' => 1]);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeOccurrenceRefs(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONArray || $value instanceof \MongoDB\Model\BSONDocument) {
            $value = $value->getArrayCopy();
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $ref): bool => is_array($ref)));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, int>  $orderById
     * @param  array<string, int>  $orderBySlug
     */
    private function resolveOccurrenceOrder(EventOccurrence $occurrence, array $orderById, array $orderBySlug): int
    {
        $occurrenceId = isset($occurrence->_id) ? (string) $occurrence->_id : '';
        if ($occurrenceId !== '' && array_key_exists($occurrenceId, $orderById)) {
            return $orderById[$occurrenceId];
        }

        $occurrenceSlug = $this->normalizeOptionalString($occurrence->occurrence_slug ?? null);
        if ($occurrenceSlug !== null && array_key_exists($occurrenceSlug, $orderBySlug)) {
            return $orderBySlug[$occurrenceSlug];
        }

        return PHP_INT_MAX;
    }

    private function dropIndexIfExists(string $collectionName, string $indexName): void
    {
        if (! Schema::hasTable($collectionName)) {
            return;
        }

        try {
            DB::connection('tenant')->getCollection($collectionName)->dropIndex($indexName);
        } catch (\Throwable) {
            // Index may not exist in partially migrated local databases.
        }
    }
};
