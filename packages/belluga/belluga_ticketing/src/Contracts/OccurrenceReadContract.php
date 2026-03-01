<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Contracts;

interface OccurrenceReadContract
{
    /**
     * @return array<string, mixed>|null
     */
    public function findOccurrence(string $eventId, string $occurrenceId): ?array;

    /**
     * @return array{event_id:string,occurrence_id:string}|null
     */
    public function resolveOccurrenceRefs(?string $eventRef, string $occurrenceRef): ?array;
}
