<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Http\Api\v1\Controllers\Concerns\HandlesTicketingDomainExceptions;
use Belluga\Ticketing\Http\Api\v1\Requests\TicketPromotionStoreRequest;
use Belluga\Ticketing\Models\Tenants\TicketPromotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TicketPromotionAdminController extends Controller
{
    use HandlesTicketingDomainExceptions;

    public function index(string $event_id, string $occurrence_id): JsonResponse
    {
        $items = TicketPromotion::query()
            ->where('event_id', (string) $event_id)
            ->where(function ($builder) use ($occurrence_id): void {
                $builder->where('scope_type', 'event')
                    ->orWhere('occurrence_id', (string) $occurrence_id)
                    ->orWhere(function ($nested): void {
                        $nested->where('scope_type', 'ticket_product')
                            ->whereNotNull('ticket_product_id');
                    });
            })
            ->orderBy('priority')
            ->orderBy('code')
            ->get()
            ->map(fn (TicketPromotion $promotion): array => [
                'promotion_id' => (string) $promotion->getAttribute('_id'),
                'code' => (string) ($promotion->code ?? ''),
                'name' => (string) ($promotion->name ?? ''),
                'scope_type' => (string) ($promotion->scope_type ?? 'event'),
                'occurrence_id' => (string) ($promotion->occurrence_id ?? ''),
                'ticket_product_id' => (string) ($promotion->ticket_product_id ?? ''),
                'type' => (string) ($promotion->type ?? ''),
                'mode' => (string) ($promotion->mode ?? 'stackable'),
                'priority' => (int) ($promotion->priority ?? 100),
                'value' => is_array($promotion->value ?? null) ? $promotion->value : [],
                'global_uses_limit' => is_numeric($promotion->global_uses_limit) ? (int) $promotion->global_uses_limit : null,
                'max_uses_per_principal' => is_numeric($promotion->max_uses_per_principal) ? (int) $promotion->max_uses_per_principal : null,
                'redeemed_total' => (int) ($promotion->redeemed_total ?? 0),
                'status' => (string) ($promotion->status ?? 'active'),
            ])
            ->values()
            ->all();

        return response()->json([
            'status' => 'ok',
            'items' => $items,
        ]);
    }

    public function store(TicketPromotionStoreRequest $request, string $event_id, string $occurrence_id): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($request, $event_id, $occurrence_id): array {
            $payload = $request->validated();
            $scopeType = (string) ($payload['scope_type'] ?? 'event');
            $code = strtoupper(trim((string) ($payload['code'] ?? '')));

            $value = is_array($payload['value'] ?? null) ? $payload['value'] : [];
            $normalizedValue = [
                'percent' => isset($value['percent']) ? (float) $value['percent'] : null,
                'amount' => isset($value['amount']) ? (int) $value['amount'] : null,
                'currency' => strtoupper((string) ($value['currency'] ?? 'BRL')),
            ];

            /** @var TicketPromotion $promotion */
            $promotion = TicketPromotion::query()->create([
                'event_id' => (string) $event_id,
                'occurrence_id' => $scopeType === 'event'
                    ? null
                    : (string) ($payload['occurrence_id'] ?? $occurrence_id),
                'ticket_product_id' => $scopeType === 'ticket_product'
                    ? (string) ($payload['ticket_product_id'] ?? '')
                    : null,
                'scope_type' => $scopeType,
                'code' => $code,
                'name' => isset($payload['name']) ? (string) $payload['name'] : $code,
                'status' => 'active',
                'type' => (string) ($payload['type'] ?? ''),
                'mode' => (string) ($payload['mode'] ?? 'stackable'),
                'priority' => (int) ($payload['priority'] ?? 100),
                'value' => $normalizedValue,
                'global_uses_limit' => isset($payload['global_uses_limit']) ? (int) $payload['global_uses_limit'] : null,
                'max_uses_per_principal' => isset($payload['max_uses_per_principal']) ? (int) $payload['max_uses_per_principal'] : null,
                'redeemed_total' => 0,
                'version' => 1,
                'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            ]);

            return [
                'status' => 'ok',
                'promotion_id' => (string) $promotion->getAttribute('_id'),
            ];
        });
    }
}

