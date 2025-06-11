<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Traits\DemandPermissions;
use App\Traits\OwnRoles;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Account extends Model
{
    use UsesTenantConnection, SoftDeletes, HasSlug, DemandPermissions, OwnRoles;

    protected $fillable = [
        'name',
        'document',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * Get the users that belong to this account
     */
    public function users(): HasMany
    {
        return $this->hasMany(AccountUser::class);
    }

    public function roles(): HasMany {
        return $this->hasMany(Role::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }
}
