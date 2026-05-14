<?php

declare(strict_types=1);

namespace App\Listeners\Attendance;

use App\Domain\Events\Events\OccurrenceAttendanceCanceled;
use App\Domain\Events\Events\OccurrenceAttendanceConfirmed;
use App\Jobs\Push\SyncEventConfirmedTopicMembershipJob;
use App\Models\Landlord\Tenant;

final class SyncOccurrenceTopicMembership
{
    public function handle(OccurrenceAttendanceConfirmed|OccurrenceAttendanceCanceled $event): void
    {
        $tenantSlug = $this->currentTenantSlug();
        if ($tenantSlug === null) {
            return;
        }

        SyncEventConfirmedTopicMembershipJob::dispatch(
            tenantSlug: $tenantSlug,
            userId: $event->userId,
            eventId: $event->eventId,
        );
    }

    private function currentTenantSlug(): ?string
    {
        $tenant = Tenant::current();
        $slug = trim((string) ($tenant?->slug ?? ''));

        return $slug === '' ? null : $slug;
    }
}
