<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class PushDevice extends Model
{
    use UsesTenantConnection;

    protected $table = 'push_devices';

    protected $fillable = [
        'tenant_id',
        'account_user_id',
        'account_ids',
        'device_id',
        'platform',
        'push_token',
        'is_active',
        'invalidated_at',
        'last_registered_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'invalidated_at' => 'datetime',
        'last_registered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
