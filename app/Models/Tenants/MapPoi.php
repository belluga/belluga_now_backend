<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class MapPoi extends Model
{
    use UsesTenantConnection;

    protected $table = 'map_pois';

    protected $fillable = [
        'ref_type',
        'ref_id',
        'ref_slug',
        'ref_path',
        'name',
        'subtitle',
        'category',
        'tags',
        'taxonomy_terms',
        'taxonomy_terms_flat',
        'location',
        'priority',
        'is_active',
        'active_window_start_at',
        'active_window_end_at',
        'time_start',
        'time_end',
        'avatar_url',
        'cover_url',
        'badge',
        'exact_key',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'active_window_start_at' => 'datetime',
        'active_window_end_at' => 'datetime',
        'time_start' => 'datetime',
        'time_end' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
