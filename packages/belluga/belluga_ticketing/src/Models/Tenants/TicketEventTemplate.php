<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketEventTemplate extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_event_templates';

    protected $fillable = [
        'template_key',
        'version',
        'status',
        'name',
        'description',
        'defaults',
        'field_states',
        'hidden_fields',
        'metadata',
    ];

    protected $casts = [
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
