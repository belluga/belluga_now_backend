<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketCheckinLog extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_checkin_logs';

    protected $fillable = [
        'ticket_unit_id',
        'event_id',
        'occurrence_id',
        'checkpoint_ref',
        'actor_ref',
        'proof_ref',
        'status',
        'reason_code',
        'idempotency_key',
        'payload_hash',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
