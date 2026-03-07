<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketProduct extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_products';

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'scope_type',
        'product_type',
        'status',
        'name',
        'slug',
        'description',
        'inventory_mode',
        'capacity_total',
        'price',
        'fee_policy',
        'bundle_items',
        'participant_binding_scope',
        'template_id',
        'template_snapshot',
        'field_states',
        'defaults',
        'metadata',
    ];

    protected $casts = [
        'capacity_total' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isUnlimited(): bool
    {
        return (string) $this->inventory_mode === 'unlimited' || $this->capacity_total === null;
    }
}
