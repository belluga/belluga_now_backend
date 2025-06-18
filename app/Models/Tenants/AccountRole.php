<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;
use Spatie\Sluggable\SlugOptions;

class AccountRole extends Model
{
    use UsesTenantConnection;

    protected $fillable = [
        'name',
        'description',
        'slug',
        'permissions',
        'account_id',
    ];

    public function account(): BelongsTo {
        return $this->belongsTo(Account::class);
    }
}
