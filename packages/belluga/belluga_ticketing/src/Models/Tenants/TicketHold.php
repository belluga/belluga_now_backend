<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketHold extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_holds';

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'scope_type',
        'scope_id',
        'principal_id',
        'principal_type',
        'status',
        'hold_token',
        'queue_entry_id',
        'payment_profile',
        'checkout_mode',
        'lines',
        'snapshot',
        'idempotency_key',
        'expires_at',
        'released_at',
        'purge_at',
        'version',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'purge_at' => 'datetime',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return (string) $this->status === 'active';
    }
}
