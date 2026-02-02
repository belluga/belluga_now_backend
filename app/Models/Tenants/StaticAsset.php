<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class StaticAsset extends Model
{
    use UsesTenantConnection, SoftDeletes, HasSlug;

    protected $table = 'static_assets';

    protected $fillable = [
        'profile_type',
        'display_name',
        'slug',
        'bio',
        'content',
        'avatar_url',
        'cover_url',
        'tags',
        'categories',
        'taxonomy_terms',
        'location',
        'is_active',
        'created_by',
        'created_by_type',
        'updated_by',
        'updated_by_type',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('display_name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }
}
