<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Tenants\Role;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait OwnRoles
{
    /**
     * Get all roles owned by this entity
     */
    public function roles(): MorphMany
    {
        return $this->morphMany(Role::class, 'owner');
    }
}
