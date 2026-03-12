<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Queue;

use Belluga\Ticketing\Models\Tenants\TicketQueueEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TicketQueueService
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function enqueue(
        string $scopeType,
        string $scopeId,
        string $eventId,
        string $occurrenceId,
        string $principalId,
        string $principalType,
        array $lines,
    ): TicketQueueEntry {
        /** @var TicketQueueEntry|null $existing */
        $existing = TicketQueueEntry::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('principal_id', $principalId)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            return $existing;
        }

        $maxPosition = (int) TicketQueueEntry::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('status', 'active')
            ->max('position');

        /** @var TicketQueueEntry $entry */
        $entry = TicketQueueEntry::query()->create([
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'principal_id' => $principalId,
            'principal_type' => $principalType,
            'status' => 'active',
            'position' => $maxPosition + 1,
            'queue_token' => (string) Str::uuid(),
            'lines' => $lines,
            'expires_at' => Carbon::now()->addMinutes(2),
        ]);

        return $entry;
    }

    public function findByTokenForPrincipal(string $queueToken, string $principalId): ?TicketQueueEntry
    {
        /** @var TicketQueueEntry|null $entry */
        $entry = TicketQueueEntry::query()
            ->where('queue_token', $queueToken)
            ->where('principal_id', $principalId)
            ->whereIn('status', ['active', 'admitted'])
            ->first();

        return $entry;
    }

    public function refreshToken(TicketQueueEntry $entry): TicketQueueEntry
    {
        $entry->queue_token = (string) Str::uuid();
        $entry->expires_at = Carbon::now()->addMinutes(2);
        $entry->save();

        return $entry->fresh();
    }

    public function markAdmitted(TicketQueueEntry $entry, string $holdId): TicketQueueEntry
    {
        $entry->status = 'admitted';
        $entry->admitted_hold_id = $holdId;
        $entry->purge_at = Carbon::now()->addDays(2);
        $entry->save();

        return $entry->fresh();
    }

    public function markExpiredStaleEntries(string $scopeType, string $scopeId): void
    {
        $now = Carbon::now();

        TicketQueueEntry::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('status', 'active')
            ->where('expires_at', '<', $now)
            ->update([
                'status' => 'expired',
                'purge_at' => $now->addHours(12),
                'updated_at' => $now,
            ]);
    }

    public function firstActiveForScope(string $scopeType, string $scopeId): ?TicketQueueEntry
    {
        /** @var TicketQueueEntry|null $entry */
        $entry = TicketQueueEntry::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('status', 'active')
            ->orderBy('position')
            ->first();

        return $entry;
    }

    public function visiblePosition(TicketQueueEntry $entry): int
    {
        return (int) TicketQueueEntry::query()
            ->where('scope_type', (string) $entry->scope_type)
            ->where('scope_id', (string) $entry->scope_id)
            ->where('status', 'active')
            ->where('position', '<=', (int) $entry->position)
            ->count();
    }
}
