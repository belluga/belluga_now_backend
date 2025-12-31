<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use Carbon\Carbon;
use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PushMessage extends Model
{
    use UsesTenantConnection;

    protected $collection = 'push_messages';

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
        'payload_template',
        'fcm_options',
        'template_defaults',
        'metrics',
        'sent_at',
        'archived_at',
    ];

    protected $casts = [
        'active' => 'boolean',
        'audience' => 'array',
        'delivery' => 'array',
        'payload_template' => 'array',
        'fcm_options' => 'array',
        'template_defaults' => 'array',
        'metrics' => 'array',
        'sent_at' => 'datetime',
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        $expiresAt = data_get($this->delivery, 'expires_at');
        if (! $expiresAt) {
            return false;
        }

        return Carbon::parse($expiresAt)->isPast();
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }
}
