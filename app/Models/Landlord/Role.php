<?php

namespace App\Models\Landlord;

use App\Models\Tenants\Account;
use App\Traits\DemandPermissions;
use App\Traits\HasOwner;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Role extends Model
{
    use UsesLandlordConnection, SoftDeletes, HasSlug, HasOwner, DemandPermissions;

    public function account(): BelongsTo {
        return $this->belongsTo(Account::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->allowDuplicateSlugs()
            ->saveSlugsTo('slug');
    }
}
