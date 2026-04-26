<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Models\Tenants\Event;
use Illuminate\Support\Carbon;

class ScheduledEventPublicationSelectionService
{
    /**
     * @return iterable<int, string>
     */
    public function dueEventIds(?Carbon $now = null): iterable
    {
        $now ??= Carbon::now();

        foreach (
            Event::query()
                ->select(['_id'])
                ->where('publication.status', 'publish_scheduled')
                ->where('publication.publish_at', '<=', $now)
                ->orderBy('publication.publish_at')
                ->orderBy('_id')
                ->cursor() as $event
        ) {
            $eventId = trim((string) ($event->_id ?? ''));
            if ($eventId === '') {
                continue;
            }

            yield $eventId;
        }
    }
}
