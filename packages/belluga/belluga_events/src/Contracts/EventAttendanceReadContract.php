<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventAttendanceReadContract
{
    /**
     * @return array<int, string>
     */
    public function listConfirmedOccurrenceIdsForUser(string $userId): array;
}
