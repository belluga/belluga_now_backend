<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketUnit extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_units';

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'ticket_product_id',
        'order_id',
        'order_item_id',
        'lifecycle_state',
        'principal_id',
        'principal_type',
        'participant_binding_scope',
        'admission_code_hash',
        'issued_at',
        'consumed_at',
        'expired_at',
        'canceled_at',
        'reissued_at',
        'transferred_at',
        'refunded_at',
        'superseded_by_ticket_unit_id',
        'version',
        'audit',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'consumed_at' => 'datetime',
        'expired_at' => 'datetime',
        'canceled_at' => 'datetime',
        'reissued_at' => 'datetime',
        'transferred_at' => 'datetime',
        'refunded_at' => 'datetime',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
