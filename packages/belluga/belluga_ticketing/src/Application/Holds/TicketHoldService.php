<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Holds;

use Belluga\Ticketing\Application\Async\TicketOutboxEmitter;
use Belluga\Ticketing\Application\Inventory\InventoryMutationService;
use Belluga\Ticketing\Models\Tenants\TicketHold;
use Belluga\Ticketing\Support\SnapshotHasher;
use Belluga\Ticketing\Support\TicketingDomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TicketHoldService
{
    public function __construct(
        private readonly InventoryMutationService $inventory,
        private readonly TicketOutboxEmitter $outbox,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    public function createOrReuseActiveHold(
        string $eventId,
        string $occurrenceId,
        string $scopeType,
        string $scopeId,
        string $principalId,
        string $principalType,
        array $lines,
        string $idempotencyKey,
        int $holdMinutes,
        string $paymentProfile,
        string $checkoutMode,
        ?string $queueEntryId = null,
    ): TicketHold {
        /** @var TicketHold|null $existing */
        $existing = TicketHold::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('principal_id', $principalId)
            ->first();

        if ($existing && in_array((string) $existing->status, ['active', 'awaiting_payment', 'confirmed'], true)) {
            return $existing;
        }

        $snapshot = $this->buildSnapshot($lines, $checkoutMode);

        /** @var TicketHold $hold */
        $hold = TicketHold::query()->create([
            'event_id' => $eventId,
            'occurrence_id' => $occurrenceId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'principal_id' => $principalId,
            'principal_type' => $principalType,
            'status' => 'active',
            'hold_token' => (string) Str::uuid(),
            'queue_entry_id' => $queueEntryId,
            'payment_profile' => $paymentProfile,
            'checkout_mode' => $checkoutMode,
            'lines' => $lines,
            'snapshot' => $snapshot,
            'idempotency_key' => $idempotencyKey,
            'expires_at' => Carbon::now()->addMinutes($holdMinutes),
            'version' => 1,
        ]);

        $this->outbox->emit(
            topic: 'ticketing.hold.created',
            payload: [
                'hold_id' => (string) $hold->getAttribute('_id'),
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'principal_id' => $principalId,
                'expires_at' => (string) $hold->expires_at,
            ],
            dedupeKey: sprintf('hold.created:%s', (string) $hold->getAttribute('_id')),
        );

        return $hold;
    }

    public function findByToken(string $holdToken): ?TicketHold
    {
        /** @var TicketHold|null $hold */
        $hold = TicketHold::query()->where('hold_token', $holdToken)->first();

        return $hold;
    }

    public function findActiveByTokenForPrincipal(string $holdToken, string $principalId): ?TicketHold
    {
        /** @var TicketHold|null $hold */
        $hold = TicketHold::query()
            ->where('hold_token', $holdToken)
            ->where('principal_id', $principalId)
            ->where('status', 'active')
            ->first();

        return $hold;
    }

    public function assertHoldActive(TicketHold $hold, string $principalId, int $graceSeconds = 30): void
    {
        if ((string) $hold->principal_id !== $principalId) {
            throw new TicketingDomainException('hold_principal_mismatch', 403);
        }

        if (! in_array((string) $hold->status, ['active', 'awaiting_payment'], true)) {
            throw new TicketingDomainException('hold_not_active', 409);
        }

        $expiresAt = Carbon::parse($hold->expires_at);
        if ($expiresAt->isFuture()) {
            return;
        }

        if ($expiresAt->copy()->addSeconds($graceSeconds)->isFuture()) {
            return;
        }

        throw new TicketingDomainException('hold_expired', 409);
    }

    public function releaseExpiredForScope(string $scopeType, string $scopeId): void
    {
        $now = Carbon::now();
        /** @var array<int, TicketHold> $expired */
        $expired = TicketHold::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('status', 'active')
            ->where('expires_at', '<', $now)
            ->limit(200)
            ->get()
            ->all();

        foreach ($expired as $hold) {
            $this->releaseHold($hold, 'expired');
        }
    }

    public function releaseHold(TicketHold $hold, string $terminalStatus): TicketHold
    {
        if (! in_array((string) $hold->status, ['active', 'awaiting_payment'], true)) {
            return $hold;
        }

        $lines = is_array($hold->lines ?? null) ? $hold->lines : [];
        $this->inventory->releaseLines($lines);

        $now = Carbon::now();
        $hold->status = $terminalStatus;
        $hold->released_at = $now;
        $hold->purge_at = $now->copy()->addDays(2);
        $hold->version = (int) ($hold->version ?? 1) + 1;
        $hold->save();

        $this->outbox->emit(
            topic: 'ticketing.hold.released',
            payload: [
                'hold_id' => (string) $hold->getAttribute('_id'),
                'event_id' => (string) $hold->event_id,
                'occurrence_id' => (string) $hold->occurrence_id,
                'status' => $terminalStatus,
            ],
            dedupeKey: sprintf('hold.released:%s:%s', (string) $hold->getAttribute('_id'), $terminalStatus),
        );

        return $hold;
    }

    public function markConfirmed(TicketHold $hold): TicketHold
    {
        $now = Carbon::now();
        $hold->status = 'confirmed';
        $hold->released_at = $now;
        $hold->purge_at = $now->copy()->addDays(90);
        $hold->version = (int) ($hold->version ?? 1) + 1;
        $hold->save();

        return $hold->fresh();
    }

    public function markAwaitingPayment(TicketHold $hold): TicketHold
    {
        $hold->status = 'awaiting_payment';
        $hold->version = (int) ($hold->version ?? 1) + 1;
        $hold->save();

        return $hold->fresh();
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function buildSnapshot(array $lines, string $checkoutMode): array
    {
        $currency = 'BRL';
        $gross = 0;

        foreach ($lines as $line) {
            $currency = (string) ($line['currency'] ?? $currency);
            $gross += (int) ($line['unit_price'] ?? 0) * (int) ($line['quantity'] ?? 0);
        }

        $snapshot = [
            'checkout_mode' => $checkoutMode,
            'currency' => $currency,
            'gross_amount' => $gross,
            'lines' => $lines,
        ];

        $snapshot['snapshot_hash'] = SnapshotHasher::hash($snapshot);

        return $snapshot;
    }
}
