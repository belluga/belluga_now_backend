<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Contracts;

interface OccurrencePublicationContract
{
    public function isOccurrencePublished(string $eventId, string $occurrenceId): bool;
}
