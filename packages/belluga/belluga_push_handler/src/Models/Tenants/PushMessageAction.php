<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PushMessageAction extends Model
{
    use UsesTenantConnection;

    protected $table = 'push_message_actions';

    protected $fillable = [
        'push_message_id',
        'user_id',
        'action',
        'step_index',
        'button_key',
        'device_id',
        'metadata',
        'idempotency_key',
        'context',
    ];

    protected $casts = [
        'step_index' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
