<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketPromotion extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_promotions';

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'ticket_product_id',
        'scope_type',
        'code',
        'name',
        'status',
        'type',
        'mode',
        'priority',
        'value',
        'global_uses_limit',
        'max_uses_per_principal',
        'redeemed_total',
        'version',
        'metadata',
    ];

    protected $casts = [
        'priority' => 'integer',
        'global_uses_limit' => 'integer',
        'max_uses_per_principal' => 'integer',
        'redeemed_total' => 'integer',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

