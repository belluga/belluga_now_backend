<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantPushSettings extends Model
{
    use UsesTenantConnection;

    protected $collection = 'tenant_push_settings';

    protected $fillable = [
        'push_message_types',
        'push_message_routes',
        'max_ttl_days',
        'telemetry',
        'firebase',
        'push',
    ];

    protected $casts = [
        'push_message_types' => 'array',
        'push_message_routes' => 'array',
        'max_ttl_days' => 'integer',
        'telemetry' => 'array',
        'firebase' => 'array',
        'push' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function current(): ?self
    {
        return static::query()->first();
    }
}
