<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Models\Tenants\AttendanceCommitment;
use Belluga\Events\Contracts\EventAttendanceReadContract;

class AttendanceCommitmentReadAdapter implements EventAttendanceReadContract
{
    public function listConfirmedEventIdsForUser(string $userId): array
    {
        return AttendanceCommitment::query()
            ->where('user_id', $userId)
            ->where('kind', 'free_confirmation')
            ->where('status', 'active')
            ->pluck('event_id')
            ->map(static fn (mixed $eventId): string => (string) $eventId)
            ->filter(static fn (string $eventId): bool => trim($eventId) !== '')
            ->unique()
            ->values()
            ->all();
    }
}
