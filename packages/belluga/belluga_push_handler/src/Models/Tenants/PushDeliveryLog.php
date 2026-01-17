<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PushDeliveryLog extends Model
{
    use UsesTenantConnection;

    protected $table = 'push_delivery_logs';

    protected $fillable = [
        'push_message_id',
        'message_instance_id',
        'batch_id',
        'token_hash',
        'status',
        'error_code',
        'error_message',
        'provider_message_id',
        'expires_at',
        'ttl_minutes',
    ];

    protected $casts = [
        'push_message_id' => 'string',
        'message_instance_id' => 'string',
        'batch_id' => 'string',
        'token_hash' => 'string',
        'status' => 'string',
        'error_code' => 'string',
        'error_message' => 'string',
        'provider_message_id' => 'string',
        'expires_at' => 'datetime',
        'ttl_minutes' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
