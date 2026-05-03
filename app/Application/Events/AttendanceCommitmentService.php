<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Models\Tenants\AttendanceCommitment;
use Belluga\Invites\Application\Mutations\InviteMutationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\UTCDateTime;

class AttendanceCommitmentService
{
    public function __construct(
        private readonly InviteMutationService $inviteMutationService,
    ) {}

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
        $now = Carbon::now();
        $timestamp = new UTCDateTime((int) $now->getTimestampMs());

        DB::connection('tenant')
            ->getMongoDB()
            ->selectCollection('attendance_commitments')
            ->updateOne(
                [
                    'user_id' => $userId,
                    'event_id' => $eventId,
                    'occurrence_id' => $occurrenceId,
                ],
                [
                    '$set' => [
                        'kind' => 'free_confirmation',
                        'status' => 'active',
                        'source' => 'direct',
                        'confirmed_at' => $timestamp,
                        'canceled_at' => null,
                        'updated_at' => $timestamp,
                    ],
                    '$setOnInsert' => [
                        'user_id' => $userId,
                        'event_id' => $eventId,
                        'occurrence_id' => $occurrenceId,
                        'created_at' => $timestamp,
                    ],
                ],
                ['upsert' => true],
            );

        /** @var AttendanceCommitment $commitment */
        $commitment = $this->findByScope($userId, $eventId, $occurrenceId)
            ?? new AttendanceCommitment([
                'user_id' => $userId,
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
            ]);

        $this->inviteMutationService->supersedePendingInvitesForDirectConfirmation(
            userId: $userId,
            eventId: $eventId,
            occurrenceId: $occurrenceId,
        );

        return $commitment->fresh();
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
