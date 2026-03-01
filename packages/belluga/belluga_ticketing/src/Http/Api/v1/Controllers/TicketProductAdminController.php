<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Http\Api\v1\Controllers;

use Belluga\Ticketing\Http\Api\v1\Controllers\Concerns\HandlesTicketingDomainExceptions;
use Belluga\Ticketing\Http\Api\v1\Requests\TicketProductStoreRequest;
use Belluga\Ticketing\Models\Tenants\TicketProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TicketProductAdminController extends Controller
{
    use HandlesTicketingDomainExceptions;

    public function index(string $event_id, string $occurrence_id): JsonResponse
    {
        $items = TicketProduct::query()
            ->where('event_id', (string) $event_id)
            ->where(function ($builder) use ($occurrence_id): void {
                $builder->where('scope_type', 'event')
                    ->orWhere('occurrence_id', (string) $occurrence_id);
            })
            ->orderBy('created_at')
            ->get()
            ->map(fn (TicketProduct $product): array => [
                'ticket_product_id' => (string) $product->getAttribute('_id'),
                'scope_type' => (string) ($product->scope_type ?? 'occurrence'),
                'product_type' => (string) ($product->product_type ?? 'ticket'),
                'name' => (string) ($product->name ?? ''),
                'slug' => (string) ($product->slug ?? ''),
                'inventory_mode' => (string) ($product->inventory_mode ?? 'limited'),
                'capacity_total' => $product->capacity_total,
                'price' => is_array($product->price ?? null) ? $product->price : ['amount' => 0, 'currency' => 'BRL'],
                'status' => (string) ($product->status ?? 'active'),
            ])
            ->values()
            ->all();

        return response()->json([
            'status' => 'ok',
            'items' => $items,
        ]);
    }

    public function store(TicketProductStoreRequest $request, string $event_id, string $occurrence_id): JsonResponse
    {
        return $this->runWithDomainGuard(function () use ($request, $event_id, $occurrence_id): array {
            $payload = $request->validated();
            $scopeType = (string) $payload['scope_type'];

            /** @var TicketProduct $product */
            $product = TicketProduct::query()->create([
                'event_id' => (string) $event_id,
                'occurrence_id' => $scopeType === 'occurrence'
                    ? (string) ($payload['occurrence_id'] ?? $occurrence_id)
                    : null,
                'scope_type' => $scopeType,
                'product_type' => (string) $payload['product_type'],
                'status' => 'active',
                'name' => (string) $payload['name'],
                'slug' => (string) $payload['slug'],
                'description' => isset($payload['description']) ? (string) $payload['description'] : null,
                'inventory_mode' => (string) $payload['inventory_mode'],
                'capacity_total' => $payload['inventory_mode'] === 'limited'
                    ? (int) ($payload['capacity_total'] ?? 0)
                    : null,
                'price' => [
                    'amount' => (int) data_get($payload, 'price.amount', 0),
                    'currency' => (string) data_get($payload, 'price.currency', 'BRL'),
                ],
                'bundle_items' => is_array($payload['bundle_items'] ?? null) ? $payload['bundle_items'] : [],
                'field_states' => is_array($payload['field_states'] ?? null) ? $payload['field_states'] : [],
                'defaults' => is_array($payload['defaults'] ?? null) ? $payload['defaults'] : [],
                'template_id' => isset($payload['template_id']) ? (string) $payload['template_id'] : null,
                'template_snapshot' => is_array($payload['template_snapshot'] ?? null) ? $payload['template_snapshot'] : null,
                'fee_policy' => is_array($payload['fee_policy'] ?? null) ? $payload['fee_policy'] : null,
                'participant_binding_scope' => isset($payload['participant_binding_scope'])
                    ? (string) $payload['participant_binding_scope']
                    : 'ticket_unit',
                'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            ]);

            return [
                'status' => 'ok',
                'ticket_product_id' => (string) $product->getAttribute('_id'),
            ];
        });
    }
}
