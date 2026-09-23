<?php

declare(strict_types=1);

namespace App\Integration\Invites;

use App\Application\Events\AttendanceCommitmentWriter;
use App\Domain\Events\Events\OccurrenceAttendanceConfirmed;
use App\Models\Tenants\AttendanceCommitment;
use Belluga\Invites\Contracts\InviteAttendanceGatewayContract;

class InviteAttendanceGatewayAdapter implements InviteAttendanceGatewayContract
{
    public function __construct(private readonly AttendanceCommitmentWriter $writer) {}

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

    public function activateFreeConfirmation(string $userId, string $eventId, string $occurrenceId): bool
    {
        return $this->writer->activateFreeConfirmation($userId, $eventId, $occurrenceId, 'invite')['activated'];
    }

    public function notifyFreeConfirmationCommitted(string $userId, string $eventId, string $occurrenceId): void
    {
        event(new OccurrenceAttendanceConfirmed($userId, $eventId, $occurrenceId));
    }
}
