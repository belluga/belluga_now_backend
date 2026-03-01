<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketOrder extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_orders';

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'account_id',
        'principal_id',
        'principal_type',
        'status',
        'hold_id',
        'checkout_mode',
        'checkout_payload_snapshot',
        'checkout_snapshot_hash',
        'financial_snapshot',
        'idempotency_key',
        'failure_code',
        'confirmed_at',
        'canceled_at',
        'refunded_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
