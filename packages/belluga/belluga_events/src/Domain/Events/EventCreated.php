<?php

declare(strict_types=1);

namespace Belluga\Events\Domain\Events;

final readonly class EventCreated
{
    public function __construct(public string $eventId) {}
}
