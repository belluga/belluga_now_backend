<?php

declare(strict_types=1);

namespace App\Listeners\Attendance;

use App\Application\Push\PushTopicMembershipService;
use App\Domain\Events\Events\OccurrenceAttendanceCanceled;
use App\Domain\Events\Events\OccurrenceAttendanceConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;

final class SyncOccurrenceTopicMembership implements ShouldQueue
{
    public function __construct(
        private readonly PushTopicMembershipService $memberships,
    ) {}

    public function handle(OccurrenceAttendanceConfirmed|OccurrenceAttendanceCanceled $event): void
    {
        if ($event instanceof OccurrenceAttendanceConfirmed) {
            $this->memberships->subscribeUserToConfirmedOccurrence($event->userId, $event->occurrenceId);

            return;
        }

        $this->memberships->unsubscribeUserFromConfirmedOccurrence($event->userId, $event->occurrenceId);
    }
}
