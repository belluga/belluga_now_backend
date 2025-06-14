<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Models\Common\UserContract;
use MongoDB\Laravel\Relations\BelongsToMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class AccountUser extends UserContract {

    use UsesTenantConnection;

    protected $table = 'account_users';

    protected string $haveAccessToKey = "account_ids";

    protected string $roleClass = Role::class;

    public function haveAccessTo(): BelongsToMany {
        return $this->belongsToMany(Account::class);
    }
}
