<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Checkout;

use Belluga\Ticketing\Models\Tenants\TicketHold;
use Belluga\Ticketing\Support\SnapshotHasher;

class CheckoutPayloadAssembler
{
    /**
     * @return array<string, mixed>
     */
    public function buildFromHold(TicketHold $hold, ?string $accountId, string $checkoutMode): array
    {
        $lines = is_array($hold->lines ?? null) ? $hold->lines : [];

        $items = [];
        $grossAmount = 0;
        $currency = 'BRL';

        foreach ($lines as $line) {
            $quantity = (int) ($line['quantity'] ?? 0);
            $unitPrice = (int) ($line['unit_price'] ?? 0);
            $lineTotal = $quantity * $unitPrice;
            $currency = (string) ($line['currency'] ?? $currency);
            $grossAmount += $lineTotal;

            $items[] = [
                'ticket_product_id' => (string) ($line['ticket_product_id'] ?? ''),
                'occurrence_id' => (string) ($line['occurrence_id'] ?? ''),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'currency' => $currency,
                'scope_type' => (string) ($line['scope_type'] ?? 'occurrence'),
                'product_type' => (string) ($line['product_type'] ?? 'ticket'),
            ];
        }

        $payload = [
            'event_id' => (string) ($hold->event_id ?? ''),
            'occurrence_id' => (string) ($hold->occurrence_id ?? ''),
            'scope_type' => (string) ($hold->scope_type ?? 'occurrence'),
            'scope_id' => (string) ($hold->scope_id ?? ''),
            'hold_id' => (string) $hold->getAttribute('_id'),
            'principal_id' => (string) ($hold->principal_id ?? ''),
            'account_id' => $accountId,
            'checkout_mode' => $checkoutMode,
            'currency' => $currency,
            'gross_amount' => $grossAmount,
            'discount_amount' => 0,
            'fee_amount' => 0,
            'buyer_total' => $grossAmount,
            'merchant_net' => $grossAmount,
            'effective_fee_policy_mode' => 'absorbed',
            'effective_fee_policy_version' => 1,
            'fee_policy_source' => 'system_default',
            'items' => $items,
        ];

        $payload['snapshot_hash'] = SnapshotHasher::hash($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $checkoutPayload
     * @return array<string, mixed>
     */
    public function buildFinancialSnapshot(array $checkoutPayload): array
    {
        return [
            'currency' => (string) ($checkoutPayload['currency'] ?? 'BRL'),
            'gross_amount' => (int) ($checkoutPayload['gross_amount'] ?? 0),
            'discount_amount' => (int) ($checkoutPayload['discount_amount'] ?? 0),
            'fee_amount' => (int) ($checkoutPayload['fee_amount'] ?? 0),
            'buyer_total' => (int) ($checkoutPayload['buyer_total'] ?? 0),
            'merchant_net' => (int) ($checkoutPayload['merchant_net'] ?? 0),
            'fee_policy_mode' => (string) ($checkoutPayload['effective_fee_policy_mode'] ?? 'absorbed'),
            'fee_policy_version' => (int) ($checkoutPayload['effective_fee_policy_version'] ?? 1),
            'pricing_version' => 1,
            'items' => $checkoutPayload['items'] ?? [],
        ];
    }
}
