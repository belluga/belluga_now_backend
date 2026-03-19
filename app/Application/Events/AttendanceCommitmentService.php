<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Models\Tenants\AttendanceCommitment;
use Belluga\Invites\Application\Mutations\InviteMutationService;
use Illuminate\Support\Carbon;

class AttendanceCommitmentService
{
    public function __construct(
        private readonly InviteMutationService $inviteMutationService,
    ) {}

    /**
     * @return array<int, string>
     */
    public function confirmedEventIds(string $userId): array
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

    public function confirm(string $userId, string $eventId, ?string $occurrenceId = null): AttendanceCommitment
    {
        $commitment = $this->findByScope($userId, $eventId, $occurrenceId);
        $now = Carbon::now();

        if (! $commitment) {
            $commitment = new AttendanceCommitment([
                'user_id' => $userId,
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
            ]);
        }

        $commitment->fill([
            'kind' => 'free_confirmation',
            'status' => 'active',
            'source' => 'direct',
            'confirmed_at' => $now,
            'canceled_at' => null,
        ]);
        $commitment->save();

        $this->inviteMutationService->supersedePendingInvitesForDirectConfirmation(
            userId: $userId,
            eventId: $eventId,
            occurrenceId: $occurrenceId,
        );

        return $commitment->fresh();
    }

    public function unconfirm(string $userId, string $eventId, ?string $occurrenceId = null): ?AttendanceCommitment
    {
        $commitment = $this->findByScope($userId, $eventId, $occurrenceId);
        if (! $commitment) {
            return null;
        }

        if ((string) $commitment->status !== 'active') {
            return $commitment;
        }

        $commitment->fill([
            'status' => 'canceled',
            'canceled_at' => Carbon::now(),
        ]);
        $commitment->save();

        return $commitment->fresh();
    }

    private function findByScope(string $userId, string $eventId, ?string $occurrenceId): ?AttendanceCommitment
    {
        /** @var AttendanceCommitment|null $commitment */
        $commitment = AttendanceCommitment::query()
            ->where('user_id', $userId)
            ->where('event_id', $eventId)
            ->where('occurrence_id', $occurrenceId)
            ->first();

        return $commitment;
    }
}
