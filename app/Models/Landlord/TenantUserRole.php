<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\Models\Landlord\Role;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

class TenantUserRole extends Model
{
    public function user(): BelongsTo {
        return $this->belongsTo(AccountUser::class);
    }

    public function tenant(): BelongsTo {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo {
        return $this->belongsTo(Role::class);
    }
}
