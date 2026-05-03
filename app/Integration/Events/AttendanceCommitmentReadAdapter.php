<?php

declare(strict_types=1);

namespace App\Integration\Events;

use App\Models\Tenants\AttendanceCommitment;
use Belluga\Events\Contracts\EventAttendanceReadContract;

class AttendanceCommitmentReadAdapter implements EventAttendanceReadContract
{
    public function listConfirmedOccurrenceIdsForUser(string $userId): array
    {
        return AttendanceCommitment::query()
            ->where('user_id', $userId)
            ->where('kind', 'free_confirmation')
            ->where('status', 'active')
            ->pluck('occurrence_id')
            ->map(static fn (mixed $occurrenceId): string => (string) $occurrenceId)
            ->filter(static fn (string $occurrenceId): bool => trim($occurrenceId) !== '')
            ->unique()
            ->values()
            ->all();
    }
}
