<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Traits\DemandPermissions;
use App\Traits\HasOwner;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Role extends Model
{
    use DemandPermissions, SoftDeletes, UsesTenantConnection, HasOwner, HasSlug;

    protected $fillable = [
        'name',
        'description',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(AccountUser::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->allowDuplicateSlugs()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }
}
