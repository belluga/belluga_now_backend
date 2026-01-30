<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantProfileType extends Model
{
    use UsesTenantConnection;

    protected $table = 'account_profile_types';

    protected $fillable = [
        'type',
        'label',
        'allowed_taxonomies',
        'capabilities',
    ];

    protected $casts = [
        'allowed_taxonomies' => 'array',
        'capabilities' => 'array',
    ];
}
