<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Realtime;

use Belluga\Ticketing\Application\Settings\TicketingRuntimeSettingsService;
use Belluga\Ticketing\Models\Tenants\TicketHold;
use Belluga\Ticketing\Models\Tenants\TicketInventoryState;
use Belluga\Ticketing\Models\Tenants\TicketProduct;
use Belluga\Ticketing\Models\Tenants\TicketQueueEntry;
use Belluga\Ticketing\Support\TicketingDomainException;
use Illuminate\Support\Carbon;

class TicketRealtimeStreamService
{
    public function __construct(
        private readonly TicketingRuntimeSettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function offerSnapshot(string $scopeType, string $scopeId): array
    {
        if (! in_array($scopeType, ['occurrence', 'event'], true)) {
            throw new TicketingDomainException('invalid_scope_type', 422);
        }

        $query = TicketProduct::query()->where('status', 'active');
        if ($scopeType === 'occurrence') {
            $query->where(static function ($builder) use ($scopeId): void {
                $builder->where('scope_type', 'event')
                    ->orWhere('occurrence_id', $scopeId);
            });
        } else {
            $query->where('event_id', $scopeId)
                ->where('scope_type', 'event');
        }

        /** @var array<int, TicketProduct> $products */
        $products = $query->orderBy('created_at')->get()->all();

        $items = [];
        foreach ($products as $product) {
            $isUnlimited = $product->isUnlimited();
            $inventoryKey = (string) ($product->scope_type ?? 'occurrence') === 'event'
                ? sprintf('event:%s', (string) ($product->event_id ?? ''))
                : (string) ($product->occurrence_id ?? '');

            /** @var TicketInventoryState|null $state */
            $state = TicketInventoryState::query()
                ->where('occurrence_id', $inventoryKey)
                ->where('ticket_product_id', (string) $product->getAttribute('_id'))
                ->first();

            $capacity = $isUnlimited ? null : (int) ($state?->capacity_total ?? $product->capacity_total ?? 0);
            $held = $isUnlimited ? 0 : (int) ($state?->held_count ?? 0);
            $sold = $isUnlimited ? 0 : (int) ($state?->sold_count ?? 0);
            $available = $isUnlimited ? null : max(0, (int) $capacity - $held - $sold);

            $items[] = [
                'ticket_product_id' => (string) $product->getAttribute('_id'),
                'scope_type' => (string) ($product->scope_type ?? 'occurrence'),
                'product_type' => (string) ($product->product_type ?? 'ticket'),
                'name' => (string) ($product->name ?? ''),
                'inventory_mode' => (string) ($product->inventory_mode ?? 'limited'),
                'available' => $available,
                'is_unlimited' => $isUnlimited,
            ];
        }

        return [
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'queue_mode' => $this->settings->queueMode(),
            'identity_mode' => $this->settings->identityMode(),
            'active_holds' => TicketHold::query()
                ->where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->whereIn('status', ['active', 'awaiting_payment'])
                ->count(),
            'active_queue' => TicketQueueEntry::query()
                ->where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->where('status', 'active')
                ->count(),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueSnapshot(string $scopeType, string $scopeId, string $principalId): array
    {
        if (! in_array($scopeType, ['occurrence', 'event'], true)) {
            throw new TicketingDomainException('invalid_scope_type', 422);
        }

        /** @var TicketQueueEntry|null $entry */
        $entry = TicketQueueEntry::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('principal_id', $principalId)
            ->whereIn('status', ['active', 'admitted'])
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $entry) {
            return [
                'state' => 'none',
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ];
        }

        $position = (int) TicketQueueEntry::query()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('status', 'active')
            ->where('position', '<=', (int) ($entry->position ?? 0))
            ->count();

        return [
            'state' => (string) ($entry->status ?? 'active'),
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'queue_entry_id' => (string) $entry->getAttribute('_id'),
            'queue_token' => (string) ($entry->queue_token ?? ''),
            'position' => $position,
            'expires_at' => $entry->expires_at instanceof Carbon ? $entry->expires_at->toISOString() : optional($entry->expires_at)?->toISOString(),
            'admitted_hold_id' => (string) ($entry->admitted_hold_id ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function holdSnapshot(string $holdId, string $principalId): array
    {
        /** @var TicketHold|null $hold */
        $hold = TicketHold::query()
            ->where('_id', $holdId)
            ->where('principal_id', $principalId)
            ->first();

        if (! $hold) {
            throw new TicketingDomainException('hold_not_found', 404);
        }

        return [
            'hold_id' => (string) $hold->getAttribute('_id'),
            'hold_token' => (string) ($hold->hold_token ?? ''),
            'status' => (string) ($hold->status ?? 'unknown'),
            'scope_type' => (string) ($hold->scope_type ?? ''),
            'scope_id' => (string) ($hold->scope_id ?? ''),
            'event_id' => (string) ($hold->event_id ?? ''),
            'occurrence_id' => (string) ($hold->occurrence_id ?? ''),
            'expires_at' => $hold->expires_at instanceof Carbon ? $hold->expires_at->toISOString() : optional($hold->expires_at)?->toISOString(),
            'checkout_mode' => (string) ($hold->checkout_mode ?? 'free'),
            'lines' => is_array($hold->lines ?? null) ? $hold->lines : [],
        ];
    }
}
