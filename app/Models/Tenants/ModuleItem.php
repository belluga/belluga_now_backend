<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\BelongsTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class ModuleItem extends Model
{
    use UsesTenantConnection;

    protected $connection = 'tenants';

    protected $fillable = [
        'module_id',
        'account_id',
        'user_id',
        'data'
    ];

    protected $casts = [
        'data' => 'array'
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class);
    }
}
