<?php

declare(strict_types=1);

namespace App\Integration\Ticketing;

use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Ticketing\Contracts\OccurrencePublicationContract;

class OccurrencePublicationAdapter implements OccurrencePublicationContract
{
    public function isOccurrencePublished(string $eventId, string $occurrenceId): bool
    {
        return EventOccurrence::query()
            ->where('_id', $occurrenceId)
            ->where('event_id', $eventId)
            ->where('is_event_published', true)
            ->whereNull('deleted_at')
            ->exists();
    }
}
