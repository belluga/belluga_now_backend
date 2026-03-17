<?php

declare(strict_types=1);

namespace Belluga\Invites\Contracts;

interface InviteAttendanceGatewayContract
{
    public function hasActiveAttendanceConfirmation(string $userId, string $eventId, ?string $occurrenceId): bool;
}
