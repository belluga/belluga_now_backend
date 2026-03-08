<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class ApiAbuseSignalAggregate extends Model
{
    use UsesLandlordConnection;

    protected $collection = 'api_abuse_signal_aggregates';

    protected $fillable = [
        'bucket_at',
        'code',
        'action',
        'level',
        'tenant_reference',
        'method',
        'path',
        'observe_mode',
        'count',
        'created_at',
        'updated_at',
        'expires_at',
    ];

    protected $casts = [
        'observe_mode' => 'bool',
        'count' => 'int',
        'bucket_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
