<?php
declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Account extends Model
{
    use UsesTenantConnection, HasSlug;

    protected $casts = [
        'settings' => 'array'
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class, 'created_by_id')->where('created_by_type', 'account');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'created_by_id')->where('created_by_type', 'account');
    }

    public function moduleItems(): HasMany
    {
        return $this->hasMany(ModuleItem::class);
    }
}
