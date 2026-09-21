<?php

declare(strict_types=1);

namespace Belluga\Invites\Contracts;

interface InviteAttendanceGatewayContract
{
    public function hasActiveAttendanceConfirmation(string $userId, string $eventId, ?string $occurrenceId): bool;

    public function activateFreeConfirmation(string $userId, string $eventId, string $occurrenceId): bool;

    public function notifyFreeConfirmationCommitted(string $userId, string $eventId, string $occurrenceId): void;
}
