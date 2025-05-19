<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;

class AccountUserRole extends Model
{
    public function user(): BelongsTo {
        return $this->belongsTo(AccountUser::class);
    }

    public function account(): BelongsTo {
        return $this->belongsTo(Account::class);
    }

    public function role(): BelongsTo {
        return $this->belongsTo(Role::class);
    }
}
