<?php

namespace App\Traits;

use App\Models\Landlord\Role;
use MongoDB\Laravel\Relations\MorphMany;

trait OwnRoles
{
    public function rolesOwner(): MorphMany {
        return $this->morphMany(Role::class,"owner");
    }
}
