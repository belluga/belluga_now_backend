<?php

declare(strict_types=1);

namespace Belluga\Invites\Application\Feed;

use Belluga\Invites\Contracts\InviteTargetReadContract;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Illuminate\Support\Carbon;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

class InviteExpiryService
{
    public function __construct(
        private readonly InviteTargetReadContract $targetRead,
        private readonly InviteProjectionService $projectionService,
    ) {}

    public function expireStaleReceiverTargets(string $receiverUserId): int
    {
        $expiredGroups = 0;
        $lastGroupKey = null;

        foreach (
            InviteEdge::query()
                ->where('receiver_user_id', $receiverUserId)
                ->whereIn('status', ['pending', 'viewed'])
                ->orderBy('event_id')
                ->orderBy('occurrence_id')
                ->cursor() as $edge
        ) {
            if (! $edge instanceof InviteEdge) {
                continue;
            }

            $groupKey = $this->groupKeyForEdge($edge);
            if ($groupKey === null || $groupKey === $lastGroupKey) {
                continue;
            }
            $lastGroupKey = $groupKey;

            if ($this->expireTargetGroupIfStale($edge)) {
                $expiredGroups++;
            }
        }

        return $expiredGroups;
    }

    public function expireStaleTargetsForCurrentTenant(): int
    {
        $expiredGroups = 0;
        $lastGroupKey = null;

        foreach (
            InviteEdge::query()
                ->whereIn('status', ['pending', 'viewed'])
                ->orderBy('receiver_user_id')
                ->orderBy('event_id')
                ->orderBy('occurrence_id')
                ->cursor() as $edge
        ) {
            if (! $edge instanceof InviteEdge) {
                continue;
            }

            $groupKey = $this->groupKeyForEdge($edge);
            if ($groupKey === null || $groupKey === $lastGroupKey) {
                continue;
            }
            $lastGroupKey = $groupKey;

            if ($this->expireTargetGroupIfStale($edge)) {
                $expiredGroups++;
            }
        }

        return $expiredGroups;
    }

    private function expireTargetGroupIfStale(InviteEdge $edge): bool
    {
        if (! $this->targetHasExpired($edge)) {
            return false;
        }

        $receiverUserId = trim((string) ($edge->receiver_user_id ?? ''));
        $eventId = trim((string) ($edge->event_id ?? ''));
        $occurrenceId = trim((string) ($edge->occurrence_id ?? ''));
        if ($receiverUserId === '' || $eventId === '' || $occurrenceId === '') {
            return false;
        }

        $updated = InviteEdge::query()
            ->where('receiver_user_id', $receiverUserId)
            ->where('event_id', $eventId)
            ->where('occurrence_id', $occurrenceId)
            ->whereIn('status', ['pending', 'viewed'])
            ->update([
                'status' => 'expired',
                'updated_at' => Carbon::now(),
            ]);

        if ($updated <= 0) {
            return false;
        }

        $this->projectionService->rebuildReceiverTargetProjection($receiverUserId, [
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
        ]);

        return true;
    }

    private function targetHasExpired(InviteEdge $edge): bool
    {
        $expiresAt = $this->resolveTargetExpiry($edge);

        return $expiresAt instanceof Carbon && $expiresAt->isPast();
    }

    private function resolveTargetExpiry(InviteEdge $edge): ?Carbon
    {
        $occurrence = $this->targetRead->findOccurrenceForEvent(
            (string) $edge->event_id,
            (string) $edge->occurrence_id,
        );
        if (! is_array($occurrence)) {
            return Carbon::now();
        }

        if (! (bool) ($occurrence['is_event_published'] ?? false)) {
            return Carbon::now();
        }

        $occurrenceExpiry = $this->normalizeCarbon($occurrence['effective_ends_at'] ?? null)
            ?? $this->normalizeCarbon($occurrence['ends_at'] ?? null);
        if ($occurrenceExpiry instanceof Carbon) {
            return $occurrenceExpiry;
        }

        if ($edge->expires_at instanceof Carbon) {
            return $edge->expires_at;
        }

        $event = $this->targetRead->findEventByIdOrSlug((string) $edge->event_id);
        if (! is_array($event)) {
            return Carbon::now();
        }

        $publication = $this->normalizeArray($event['publication'] ?? []);
        if (($publication['status'] ?? null) !== 'published') {
            return Carbon::now();
        }

        return $this->normalizeCarbon($event['date_time_end'] ?? null);
    }

    private function groupKeyForEdge(InviteEdge $edge): ?string
    {
        $receiverUserId = trim((string) ($edge->receiver_user_id ?? ''));
        $eventId = trim((string) ($edge->event_id ?? ''));
        $occurrenceId = trim((string) ($edge->occurrence_id ?? ''));

        if ($receiverUserId === '' || $eventId === '' || $occurrenceId === '') {
            return null;
        }

        return $receiverUserId.'::'.$eventId.'::'.$occurrenceId;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }
        if (is_object($value)) {
            return (array) $value;
        }

        return [];
    }

    private function normalizeCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof UTCDateTime) {
            return Carbon::instance($value->toDateTime());
        }
        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        return null;
    }
}
