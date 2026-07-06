<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use MongoDB\BSON\ObjectId;

class EventOccurrenceOrphanInventoryService
{
    /**
     * @return array{
     *   totals: array{
     *     scanned_occurrences: int,
     *     orphan_occurrences: int,
     *     active_bypass: int,
     *     legacy_residue: int
     *   },
     *   rows: array<int, array{
     *     occurrence_id: string,
     *     event_id: string,
     *     occurrence_slug: ?string,
     *     title: string,
     *     deleted_at: ?string,
     *     classification: string,
     *     classification_basis: string
     *   }>
     * }
     */
    public function inventoryCurrentTenant(): array
    {
        $occurrences = EventOccurrence::withTrashed()
            ->orderBy('event_id')
            ->orderBy('_id')
            ->get()
            ->values();

        $eventIds = $occurrences
            ->map(static fn (EventOccurrence $occurrence): string => trim((string) ($occurrence->event_id ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $eventsById = Event::withTrashed()
            ->whereIn('_id', $this->buildDocumentIdCandidates($eventIds))
            ->get()
            ->keyBy(static fn (Event $event): string => (string) $event->_id);

        $rows = [];
        foreach ($occurrences as $occurrence) {
            $eventId = trim((string) ($occurrence->event_id ?? ''));
            if ($eventId !== '' && $eventsById->has($eventId)) {
                continue;
            }

            $classification = $occurrence->deleted_at !== null
                ? 'legacy_residue'
                : 'active_bypass';

            $rows[] = [
                'occurrence_id' => (string) ($occurrence->_id ?? ''),
                'event_id' => $eventId,
                'occurrence_slug' => $this->normalizeOptionalString($occurrence->occurrence_slug ?? null),
                'title' => (string) ($occurrence->title ?? ''),
                'deleted_at' => $occurrence->deleted_at?->toISOString(),
                'classification' => $classification,
                'classification_basis' => $occurrence->deleted_at !== null
                    ? 'missing_parent_event + soft_deleted_occurrence'
                    : 'missing_parent_event + live_occurrence',
            ];
        }

        return [
            'totals' => [
                'scanned_occurrences' => $occurrences->count(),
                'orphan_occurrences' => count($rows),
                'active_bypass' => count(array_filter(
                    $rows,
                    static fn (array $row): bool => $row['classification'] === 'active_bypass'
                )),
                'legacy_residue' => count(array_filter(
                    $rows,
                    static fn (array $row): bool => $row['classification'] === 'legacy_residue'
                )),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, string|ObjectId>
     */
    private function buildDocumentIdCandidates(array $ids): array
    {
        $candidates = [];
        foreach ($ids as $id) {
            $normalized = trim((string) $id);
            if ($normalized === '') {
                continue;
            }

            $candidates[] = $normalized;

            try {
                $candidates[] = new ObjectId($normalized);
            } catch (\Throwable) {
                // Keep the string candidate only when the value is not a valid ObjectId.
            }
        }

        return $candidates;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
