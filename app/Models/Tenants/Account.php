<?php

namespace App\Models\Tenants;

use App\Models\Landlord\UserRole;
use App\Traits\DemandPermissions;
use App\Traits\HasOwner;
use App\Traits\OwnRoles;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Account extends Model
{
    use UsesTenantConnection, SoftDeletes, HasSlug, HasOwner, DemandPermissions, OwnRoles;

    public function userRoles(): HasMany {
        return $this->hasMany(UserRole::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }
}
