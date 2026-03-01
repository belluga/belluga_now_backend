<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketOrderItem extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_order_items';

    protected $fillable = [
        'order_id',
        'event_id',
        'occurrence_id',
        'ticket_product_id',
        'status',
        'quantity',
        'unit_price',
        'currency',
        'discount_amount',
        'fee_amount',
        'line_total',
        'snapshot',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'discount_amount' => 'integer',
        'fee_amount' => 'integer',
        'line_total' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
