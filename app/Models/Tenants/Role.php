<?php

namespace App\Models\Tenants;

use App\Traits\DemandPermissions;
use App\Traits\HasOwner;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Role extends Model
{
    use UsesTenantConnection, SoftDeletes, HasSlug, HasOwner, DemandPermissions;

    public function account(): BelongsTo {
        return $this->belongsTo(Account::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }
}
