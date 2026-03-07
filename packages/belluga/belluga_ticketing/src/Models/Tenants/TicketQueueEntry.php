<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketQueueEntry extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_queue_entries';

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'scope_type',
        'scope_id',
        'principal_id',
        'principal_type',
        'status',
        'position',
        'queue_token',
        'lines',
        'admitted_hold_id',
        'expires_at',
        'purge_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'expires_at' => 'datetime',
        'purge_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
