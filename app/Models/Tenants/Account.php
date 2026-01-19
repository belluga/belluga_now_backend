<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Traits\OwnRoles;
use Illuminate\Support\Facades\Context;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\HasMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Account extends Model
{
    use UsesTenantConnection, SoftDeletes, HasSlug, OwnRoles;

    static protected string $container_key = 'currentAccount';

    static protected string $context_key = 'accountId';

    protected $fillable = [
        'name',
        'document',
        'organization_id',
        'created_by',
        'created_by_type',
        'updated_by',
        'updated_by_type',
    ];

    protected $casts = [
    ];

    /**
     * Get the users that belong to this account
     */
    public function users(): HasMany
    {
        return $this->hasMany(AccountUser::class);
    }

    public function roleTemplates(): HasMany {
        return $this->hasMany(AccountRoleTemplate::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug');
    }

    public function forget(): static
    {
        app()->forgetInstance(static::$container_key);

        Context::forget(static::$context_key);

        return $this;
    }

    public static function current(): ?static
    {

        if (! app()->has(static::$container_key)) {
            return null;
        }

        return app(static::$container_key);
    }

    public function makeCurrent(): static
    {
        if ($this->isCurrent()) {
            return $this;
        }

        app()->instance(static::$container_key, $this);

        Context::add(static::$context_key, $this->id);

        return $this;
    }

    public function isCurrent(): bool {
        return $this->id === Context::get(static::$context_key);
    }

}
