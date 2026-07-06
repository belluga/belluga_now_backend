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
        $rows = [];
        $scannedOccurrences = 0;
        $activeBypass = 0;
        $legacyResidue = 0;
        $currentEventId = null;
        $currentEventExists = false;

        foreach (EventOccurrence::withTrashed()->orderBy('event_id')->orderBy('_id')->cursor() as $occurrence) {
            $scannedOccurrences++;
            $eventId = trim((string) ($occurrence->event_id ?? ''));
            if ($eventId !== $currentEventId) {
                $currentEventId = $eventId;
                $currentEventExists = $eventId !== '' && $this->eventExists($eventId);
            }

            if ($currentEventExists) {
                continue;
            }

            $classification = $occurrence->deleted_at !== null
                ? 'legacy_residue'
                : 'active_bypass';

            if ($classification === 'legacy_residue') {
                $legacyResidue++;
            } else {
                $activeBypass++;
            }

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
                'scanned_occurrences' => $scannedOccurrences,
                'orphan_occurrences' => count($rows),
                'active_bypass' => $activeBypass,
                'legacy_residue' => $legacyResidue,
            ],
            'rows' => $rows,
        ];
    }

    private function eventExists(string $eventId): bool
    {
        return Event::withTrashed()
            ->whereIn('_id', $this->buildDocumentIdCandidates([$eventId]))
            ->exists();
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
