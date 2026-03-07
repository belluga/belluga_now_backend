<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventProjectionSyncContract
{
    public function upsertEvent(string $eventId): void;

    public function deleteEvent(string $eventId): void;
}
