<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PushDeliveryLog extends Model
{
    use UsesTenantConnection;

    protected $collection = 'push_delivery_logs';

    protected $fillable = [
        'push_message_id',
        'batch_id',
        'token_hash',
        'status',
        'error_code',
        'error_message',
        'provider_message_id',
    ];

    protected $casts = [
        'push_message_id' => 'string',
        'batch_id' => 'string',
        'token_hash' => 'string',
        'status' => 'string',
        'error_code' => 'string',
        'error_message' => 'string',
        'provider_message_id' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
