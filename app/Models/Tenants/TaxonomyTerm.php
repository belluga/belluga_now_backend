<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TaxonomyTerm extends Model
{
    use UsesTenantConnection;

    protected $table = 'taxonomy_terms';

    protected $fillable = [
        'taxonomy_id',
        'slug',
        'name',
    ];

    protected $casts = [
    ];
}
