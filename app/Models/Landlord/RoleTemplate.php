<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class RoleTemplate extends Model
{
    use UsesLandlordConnection, HasSlug;

    protected $fillable = [
        'name',
        'description',
        'type', // 'tenant', 'account'
        'permissions_schema'
    ];

    protected $casts = [
        'permissions_schema' => 'array'
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }
}
