<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class StaticProfileType extends Model
{
    use UsesTenantConnection;

    protected $table = 'static_profile_types';

    protected $fillable = [
        'type',
        'label',
        'allowed_taxonomies',
        'capabilities',
    ];

    protected $casts = [
    ];
}
