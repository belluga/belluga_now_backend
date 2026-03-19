<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class AccountProfile extends Model
{
    use HasSlug, SoftDeletes, UsesTenantConnection;

    protected $table = 'account_profiles';

    protected $fillable = [
        'account_id',
        'profile_type',
        'display_name',
        'slug',
        'taxonomy_terms',
        'location',
        'bio',
        'content',
        'avatar_url',
        'cover_url',
        'is_active',
        'is_verified',
        'created_by',
        'created_by_type',
        'updated_by',
        'updated_by_type',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'is_verified' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('display_name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }
}
