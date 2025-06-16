<?php

namespace App\Models\Landlord;

use App\Traits\DemandPermissions;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class TenantRole extends Model
{
    use UsesLandlordConnection, SoftDeletes, HasSlug, DemandPermissions;

    protected $fillable = [
        'name',
        'slug',
        'permissions',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->allowDuplicateSlugs()
            ->saveSlugsTo('slug');
    }
}
