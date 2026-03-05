<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Inventory;

use Belluga\Ticketing\Models\Tenants\TicketInventoryState;
use Belluga\Ticketing\Models\Tenants\TicketProduct;
use Belluga\Ticketing\Support\TicketingDomainException;

class InventoryMutationService
{
    /**
     * @return array<int, TicketProduct>
     */
    public function listSellableProducts(string $eventId, ?string $occurrenceId = null): array
    {
        $query = TicketProduct::query()
            ->where('event_id', $eventId)
            ->where('status', 'active');

        if ($occurrenceId !== null) {
            $query->where(function ($builder) use ($occurrenceId): void {
                $builder->where('scope_type', 'event')
                    ->orWhere('occurrence_id', $occurrenceId);
            });
        }

        /** @var array<int, TicketProduct> $products */
        $products = $query->orderBy('created_at')->get()->all();

        return $products;
    }

    /**
     * @return array{available:int|null,is_unlimited:bool}
     */
    public function availabilityForProduct(TicketProduct $product, string $eventId, string $occurrenceId): array
    {
        $state = $this->ensureInventoryStateForProduct(
            product: $product,
            eventId: $eventId,
            inventoryOccurrenceId: $this->inventoryOccurrenceKey($product, $eventId, $occurrenceId)
        );

        return [
            'available' => $state->available(),
            'is_unlimited' => $product->isUnlimited(),
        ];
    }

    /**
     * @param array<int, array{ticket_product_id:string, quantity:int}> $items
     * @return array<int, array<string, mixed>>
     */
    public function hydrateLines(array $items, string $eventId, string $occurrenceId): array
    {
        if ($items === []) {
            throw new TicketingDomainException('items_required', 422);
        }

        $grouped = [];
        foreach ($items as $item) {
            $productId = (string) ($item['ticket_product_id'] ?? '');
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($productId === '' || $quantity <= 0) {
                throw new TicketingDomainException('invalid_items_payload', 422);
            }

            $grouped[$productId] = ($grouped[$productId] ?? 0) + $quantity;
        }

        $lines = [];
        foreach ($grouped as $productId => $quantity) {
            /** @var TicketProduct|null $product */
            $product = TicketProduct::query()
                ->where('_id', $productId)
                ->where('event_id', $eventId)
                ->where('status', 'active')
                ->first();

            if (! $product) {
                throw new TicketingDomainException('ticket_product_not_found', 404);
            }

            if ((string) ($product->scope_type ?? 'occurrence') === 'occurrence' && (string) $product->occurrence_id !== $occurrenceId) {
                throw new TicketingDomainException('ticket_product_outside_occurrence_scope', 422);
            }

            $inventoryOccurrenceId = $this->inventoryOccurrenceKey($product, $eventId, $occurrenceId);
            $state = $this->ensureInventoryStateForProduct($product, $eventId, $inventoryOccurrenceId);

            $price = is_array($product->price ?? null) ? $product->price : [];
            $unitPrice = max(0, (int) ($price['amount'] ?? 0));
            $currency = (string) ($price['currency'] ?? 'BRL');

            $lines[] = [
                'event_id' => $eventId,
                'occurrence_id' => $occurrenceId,
                'inventory_occurrence_id' => $inventoryOccurrenceId,
                'ticket_product_id' => (string) $product->getAttribute('_id'),
                'inventory_state_id' => (string) $state->getAttribute('_id'),
                'product_type' => (string) ($product->product_type ?? 'ticket'),
                'scope_type' => (string) ($product->scope_type ?? 'occurrence'),
                'quantity' => (int) $quantity,
                'unit_price' => $unitPrice,
                'currency' => $currency,
                'is_unlimited' => $product->isUnlimited(),
                'participant_binding_scope' => (string) ($product->participant_binding_scope ?? 'ticket_unit'),
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function hydrateSingleLine(string $ticketProductId, int $quantity, string $eventId, string $occurrenceId): array
    {
        $lines = $this->hydrateLines([
            [
                'ticket_product_id' => $ticketProductId,
                'quantity' => $quantity,
            ],
        ], $eventId, $occurrenceId);

        return $lines[0];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array{limited:bool,insufficient:bool,available:array<int,array<string,mixed>>}
     */
    public function previewAvailability(array $lines): array
    {
        $limited = false;
        $insufficient = false;
        $available = [];

        foreach ($lines as $line) {
            if ((bool) ($line['is_unlimited'] ?? false)) {
                $available[] = [
                    'ticket_product_id' => (string) $line['ticket_product_id'],
                    'quantity_requested' => (int) $line['quantity'],
                    'available' => null,
                ];

                continue;
            }

            $limited = true;
            /** @var TicketInventoryState|null $state */
            $state = TicketInventoryState::query()->find((string) $line['inventory_state_id']);
            if (! $state) {
                throw new TicketingDomainException('inventory_state_not_found', 500);
            }

            $current = $state->available();
            $requested = (int) $line['quantity'];
            if ($current !== null && $current < $requested) {
                $insufficient = true;
            }

            $available[] = [
                'ticket_product_id' => (string) $line['ticket_product_id'],
                'quantity_requested' => $requested,
                'available' => $current,
            ];
        }

        return [
            'limited' => $limited,
            'insufficient' => $insufficient,
            'available' => $available,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    public function reserveLines(array $lines): void
    {
        foreach ($lines as $line) {
            if ((bool) ($line['is_unlimited'] ?? false)) {
                continue;
            }

            /** @var TicketInventoryState|null $state */
            $state = TicketInventoryState::query()->find((string) $line['inventory_state_id']);
            if (! $state) {
                throw new TicketingDomainException('inventory_state_not_found', 500);
            }

            $requested = (int) $line['quantity'];
            $available = $state->available();
            if ($available !== null && $available < $requested) {
                throw new TicketingDomainException('sold_out', 409);
            }

            $updated = TicketInventoryState::query()
                ->where('_id', (string) $state->getAttribute('_id'))
                ->where('held_count', (int) $state->held_count)
                ->where('sold_count', (int) $state->sold_count)
                ->where('version', (int) $state->version)
                ->update([
                    'held_count' => (int) $state->held_count + $requested,
                    'version' => (int) $state->version + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new TicketingDomainException('inventory_conflict', 409);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    public function releaseLines(array $lines): void
    {
        foreach ($lines as $line) {
            if ((bool) ($line['is_unlimited'] ?? false)) {
                continue;
            }

            /** @var TicketInventoryState|null $state */
            $state = TicketInventoryState::query()->find((string) $line['inventory_state_id']);
            if (! $state) {
                throw new TicketingDomainException('inventory_state_not_found', 500);
            }

            $quantity = (int) $line['quantity'];
            if ((int) $state->held_count < $quantity) {
                throw new TicketingDomainException('inventory_release_underflow', 500);
            }

            $updated = TicketInventoryState::query()
                ->where('_id', (string) $state->getAttribute('_id'))
                ->where('held_count', (int) $state->held_count)
                ->where('sold_count', (int) $state->sold_count)
                ->where('version', (int) $state->version)
                ->update([
                    'held_count' => (int) $state->held_count - $quantity,
                    'version' => (int) $state->version + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new TicketingDomainException('inventory_conflict', 409);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    public function confirmSaleFromHoldLines(array $lines): void
    {
        foreach ($lines as $line) {
            if ((bool) ($line['is_unlimited'] ?? false)) {
                continue;
            }

            /** @var TicketInventoryState|null $state */
            $state = TicketInventoryState::query()->find((string) $line['inventory_state_id']);
            if (! $state) {
                throw new TicketingDomainException('inventory_state_not_found', 500);
            }

            $quantity = (int) $line['quantity'];
            if ((int) $state->held_count < $quantity) {
                throw new TicketingDomainException('inventory_hold_missing', 409);
            }

            $updated = TicketInventoryState::query()
                ->where('_id', (string) $state->getAttribute('_id'))
                ->where('held_count', (int) $state->held_count)
                ->where('sold_count', (int) $state->sold_count)
                ->where('version', (int) $state->version)
                ->update([
                    'held_count' => (int) $state->held_count - $quantity,
                    'sold_count' => (int) $state->sold_count + $quantity,
                    'version' => (int) $state->version + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new TicketingDomainException('inventory_conflict', 409);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    public function returnSoldLines(array $lines): void
    {
        foreach ($lines as $line) {
            if ((bool) ($line['is_unlimited'] ?? false)) {
                continue;
            }

            /** @var TicketInventoryState|null $state */
            $state = TicketInventoryState::query()->find((string) $line['inventory_state_id']);
            if (! $state) {
                throw new TicketingDomainException('inventory_state_not_found', 500);
            }

            $quantity = (int) $line['quantity'];
            if ((int) $state->sold_count < $quantity) {
                throw new TicketingDomainException('inventory_return_underflow', 500);
            }

            $updated = TicketInventoryState::query()
                ->where('_id', (string) $state->getAttribute('_id'))
                ->where('held_count', (int) $state->held_count)
                ->where('sold_count', (int) $state->sold_count)
                ->where('version', (int) $state->version)
                ->update([
                    'sold_count' => (int) $state->sold_count - $quantity,
                    'version' => (int) $state->version + 1,
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new TicketingDomainException('inventory_conflict', 409);
            }
        }
    }

    public function ensureInventoryStateForProduct(TicketProduct $product, string $eventId, string $inventoryOccurrenceId): TicketInventoryState
    {
        /** @var TicketInventoryState $state */
        $state = TicketInventoryState::query()->firstOrCreate(
            [
                'occurrence_id' => $inventoryOccurrenceId,
                'ticket_product_id' => (string) $product->getAttribute('_id'),
            ],
            [
                'event_id' => $eventId,
                'capacity_total' => $product->isUnlimited() ? null : (int) ($product->capacity_total ?? 0),
                'held_count' => 0,
                'sold_count' => 0,
                'version' => 1,
            ]
        );

        if ($state->capacity_total === null && ! $product->isUnlimited()) {
            $state->capacity_total = (int) ($product->capacity_total ?? 0);
            $state->save();
        }

        return $state;
    }

    private function inventoryOccurrenceKey(TicketProduct $product, string $eventId, string $occurrenceId): string
    {
        return (string) ($product->scope_type ?? 'occurrence') === 'event'
            ? sprintf('event:%s', $eventId)
            : $occurrenceId;
    }
}
