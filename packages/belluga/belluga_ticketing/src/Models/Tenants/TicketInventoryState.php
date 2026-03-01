<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TicketInventoryState extends Model
{
    use UsesTenantConnection;

    protected $table = 'ticket_inventory_states';

    protected $fillable = [
        'event_id',
        'occurrence_id',
        'ticket_product_id',
        'capacity_total',
        'held_count',
        'sold_count',
        'version',
    ];

    protected $casts = [
        'capacity_total' => 'integer',
        'held_count' => 'integer',
        'sold_count' => 'integer',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function available(): ?int
    {
        if ($this->capacity_total === null) {
            return null;
        }

        return max(0, (int) $this->capacity_total - (int) $this->held_count - (int) $this->sold_count);
    }
}
