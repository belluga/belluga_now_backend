<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketUnitAuditEvent extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_unit_audit_events';

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'operation',
        'scope_binding',
        'source_ticket_unit_id',
        'target_ticket_unit_ids',
        'actor_ref',
        'reason_code',
        'reason_text',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
