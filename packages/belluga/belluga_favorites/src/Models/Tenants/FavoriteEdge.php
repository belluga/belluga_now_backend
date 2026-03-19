<?php

declare(strict_types=1);

namespace Belluga\Favorites\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class FavoriteEdge extends Model
{
    use UsesTenantConnection;

    protected $table = 'favorite_edges';

    protected $guarded = [];

    protected $casts = [
        'favorited_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
