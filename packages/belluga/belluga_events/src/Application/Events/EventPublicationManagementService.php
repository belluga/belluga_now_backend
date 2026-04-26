<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Domain\Events\EventUpdated;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EventPublicationManagementService
{
    public function __construct(
        private readonly EventAggregateWriteService $eventAggregateWrites,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return array{published: bool, from_status?: string, to_status?: string, publish_at?: mixed, mirrored_occurrences?: int}
     */
    public function publishScheduledEventIfDue(string $eventId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $result = $this->eventAggregateWrites->publishScheduledEventIfDue($eventId, $now);

        if (($result['published'] ?? false) === true) {
            $this->events->dispatch(new EventUpdated($eventId));
            Log::info('events_publication_transition_applied', [
                'event_id' => $eventId,
                'from_status' => (string) ($result['from_status'] ?? 'publish_scheduled'),
                'to_status' => (string) ($result['to_status'] ?? 'published'),
                'publish_at' => $this->formatDate($result['publish_at'] ?? null),
                'mirrored_occurrence_count' => (int) ($result['mirrored_occurrences'] ?? 0),
            ]);
        }

        return $result;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toISOString();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toISOString();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toISOString();
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
