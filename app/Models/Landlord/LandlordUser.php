<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\Models\Common\UserContract;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class LandlordUser extends UserContract {

    use UsesLandlordConnection;

    protected $table = 'landlord_users';

    protected string $haveAccessToKey = "tenant_ids";

    protected string $roleClass = Role::class;

    public function haveAccessTo(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class);
    }

}
