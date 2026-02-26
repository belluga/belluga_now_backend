<?php

declare(strict_types=1);

namespace Belluga\Events\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Event extends Model
{
    use UsesTenantConnection, SoftDeletes, HasSlug;

    protected $table = 'events';

    protected $fillable = [
        'account_id',
        'account_profile_id',
        'slug',
        'type',
        'title',
        'content',
        'venue',
        'geo_location',
        'thumb',
        'date_time_start',
        'date_time_end',
        'artists',
        'tags',
        'categories',
        'taxonomy_terms',
        'capabilities',
        'confirmed_user_ids',
        'received_invites',
        'sent_invites',
        'friends_going',
        'publication',
        'is_active',
    ];

    protected $casts = [
        'date_time_start' => 'datetime',
        'date_time_end' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('title')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }
}
