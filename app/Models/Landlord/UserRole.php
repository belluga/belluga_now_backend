<?php

declare(strict_types=1);

namespace App\Models\Landlord;

use App\Models\Tenants\Account;
use App\Models\Tenants\AccountUser;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use MongoDB\Laravel\Relations\MorphTo;

class UserRole extends Model
{
    public function user(): BelongsTo {
        return $this->belongsTo(AccountUser::class);
    }

    public function entity(): MorphTo {
        return $this->morphTo();
    }

    public function role(): BelongsTo {
        return $this->belongsTo(Role::class);
    }
}
