<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class ApiAbuseSignal extends Model
{
    use UsesLandlordConnection;

    protected $collection = 'api_abuse_signals';

    protected $fillable = [
        'kind',
        'code',
        'action',
        'level',
        'level_source',
        'tenant_reference',
        'principal_hash',
        'method',
        'path',
        'correlation_id',
        'cf_ray_id',
        'observe_mode',
        'blocked',
        'retry_after',
        'state_count',
        'metadata',
        'created_at',
        'updated_at',
        'expires_at',
    ];

    protected $casts = [
        'observe_mode' => 'bool',
        'blocked' => 'bool',
        'retry_after' => 'int',
        'state_count' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
