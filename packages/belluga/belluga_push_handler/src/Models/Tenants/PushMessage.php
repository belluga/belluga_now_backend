<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use Carbon\Carbon;
use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PushMessage extends Model
{
    use UsesTenantConnection;

    protected $table = 'push_messages';

    protected $fillable = [
        'scope',
        'partner_id',
        'internal_name',
        'title_template',
        'body_template',
        'type',
        'active',
        'status',
        'audience',
        'delivery',
        'delivery_deadline_at',
        'payload_template',
        'fcm_options',
        'template_defaults',
        'metrics',
        'sent_at',
        'archived_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'delivery_deadline_at' => 'datetime',
        'sent_at' => 'datetime',
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        $deadline = $this->delivery_deadline_at;
        if (! $deadline) {
            return false;
        }

        return Carbon::parse($deadline)->isPast();
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }
}
