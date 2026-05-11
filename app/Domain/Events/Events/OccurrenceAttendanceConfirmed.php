<?php

declare(strict_types=1);

namespace App\Domain\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class OccurrenceAttendanceConfirmed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $userId,
        public string $eventId,
        public string $occurrenceId,
    ) {}
}
