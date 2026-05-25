<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Events\Events\OccurrenceAttendanceCanceled;
use App\Domain\Events\Events\OccurrenceAttendanceConfirmed;
use App\Models\Tenants\AttendanceCommitment;
use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Invites\Application\Mutations\InviteMutationService;
use Illuminate\Support\Carbon;
use RuntimeException;

class AttendanceCommitmentService
{
    public function __construct(
        private readonly InviteMutationService $inviteMutationService,
        private readonly EventTransactionRunner $transactions,
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

    public function hasConfirmedEvent(string $userId, string $eventId): bool
    {
        $userId = trim($userId);
        $eventId = trim($eventId);
        if ($userId === '' || $eventId === '') {
            return false;
        }

        return AttendanceCommitment::query()
            ->where('user_id', $userId)
            ->where('event_id', $eventId)
            ->where('kind', 'free_confirmation')
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function confirmedOccurrenceIds(string $userId): array
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

    public function confirm(string $userId, string $eventId, string $occurrenceId): AttendanceCommitment
    {
        $this->inviteMutationService->prepareReceiverForDirectConfirmation($userId);

        /** @var AttendanceCommitment $commitment */
        $commitment = $this->transactions->run(function () use ($userId, $eventId, $occurrenceId): AttendanceCommitment {
            $now = Carbon::now();

            $commitment = $this->findByScope($userId, $eventId, $occurrenceId)
                ?? new AttendanceCommitment([
                    'user_id' => $userId,
                    'event_id' => $eventId,
                    'occurrence_id' => $occurrenceId,
                ]);
            $commitment->fill([
                'kind' => 'free_confirmation',
                'status' => 'active',
                'source' => 'direct',
                'confirmed_at' => $now,
                'canceled_at' => null,
            ]);
            $commitment->save();
            $commitment = $commitment->fresh();
            if (! $commitment instanceof AttendanceCommitment) {
                throw new RuntimeException('Attendance confirmation could not be reloaded after write.');
            }

            $this->inviteMutationService->supersedePendingInvitesForDirectConfirmation(
                userId: $userId,
                eventId: $eventId,
                occurrenceId: $occurrenceId,
            );

            return $commitment;
        });

        event(new OccurrenceAttendanceConfirmed($userId, $eventId, $occurrenceId));

        return $commitment->fresh() ?? $commitment;
    }

    public function unconfirm(string $userId, string $eventId, string $occurrenceId): ?AttendanceCommitment
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

        event(new OccurrenceAttendanceCanceled($userId, $eventId, $occurrenceId));

        return $commitment->fresh();
    }

    private function findByScope(string $userId, string $eventId, string $occurrenceId): ?AttendanceCommitment
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
