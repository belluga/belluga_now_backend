<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketPromotionRedemption extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_promotion_redemptions';

    protected $fillable = [
        'promotion_id',
        'order_id',
        'principal_id',
        'principal_type',
        'event_id',
        'occurrence_id',
        'delta_amount',
        'currency',
        'metadata',
    ];

    protected $casts = [
        'delta_amount' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

