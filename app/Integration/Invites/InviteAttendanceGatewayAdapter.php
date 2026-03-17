<?php

declare(strict_types=1);

namespace App\Integration\Invites;

use App\Models\Tenants\AttendanceCommitment;
use Belluga\Invites\Contracts\InviteAttendanceGatewayContract;

class InviteAttendanceGatewayAdapter implements InviteAttendanceGatewayContract
{
    public function hasActiveAttendanceConfirmation(string $userId, string $eventId, ?string $occurrenceId): bool
    {
        return AttendanceCommitment::query()
            ->where('user_id', $userId)
            ->where('event_id', $eventId)
            ->where('occurrence_id', $occurrenceId)
            ->where('kind', 'free_confirmation')
            ->where('status', 'active')
            ->exists();
    }
}
