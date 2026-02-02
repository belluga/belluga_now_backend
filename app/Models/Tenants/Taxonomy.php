<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Taxonomy extends Model
{
    use UsesTenantConnection;

    protected $table = 'taxonomies';

    protected $fillable = [
        'slug',
        'name',
        'applies_to',
        'icon',
        'color',
    ];

    protected $casts = [
    ];
}
