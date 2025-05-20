<?php

namespace App\Traits;

use App\Enums\PermissionsActions;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\Role;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\HasMany;
use MongoDB\Laravel\Relations\MorphMany;
use MongoDB\Laravel\Relations\MorphTo;

trait OwnRoles
{
    public function rolesOwner(): MorphMany {
        return $this->morphMany(Role::class,"owner");
    }
}
