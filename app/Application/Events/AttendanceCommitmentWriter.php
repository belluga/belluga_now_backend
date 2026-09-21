<?php

declare(strict_types=1);

namespace App\Application\Events;

use App\Models\Tenants\AttendanceCommitment;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Transaction-neutral persistence boundary for free confirmations.
 */
class AttendanceCommitmentWriter
{
    /**
     * @return array{commitment: AttendanceCommitment, activated: bool}
     */
    public function activateFreeConfirmation(
        string $userId,
        string $eventId,
        string $occurrenceId,
        string $source,
    ): array
    {
        /** @var AttendanceCommitment|null $existing */
        $existing = AttendanceCommitment::query()
            ->where('user_id', $userId)
            ->where('event_id', $eventId)
            ->where('occurrence_id', $occurrenceId)
            ->first();
        if ($existing instanceof AttendanceCommitment && (string) $existing->status === 'active') {
            return ['commitment' => $existing, 'activated' => false];
        }

        $commitment = $existing ?? new AttendanceCommitment([
            'user_id' => $userId,
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
        ]);
        $commitment->fill([
            'kind' => 'free_confirmation',
            'status' => 'active',
            'source' => $source,
            'confirmed_at' => Carbon::now(),
            'canceled_at' => null,
        ]);
        $commitment->save();
        $commitment = $commitment->fresh();
        if (! $commitment instanceof AttendanceCommitment) {
            throw new RuntimeException('Attendance confirmation could not be reloaded after write.');
        }

        return [
            'commitment' => $commitment,
            'activated' => true,
        ];
    }
}
