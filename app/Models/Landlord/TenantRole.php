<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class TenantRole extends Model
{
    use UsesLandlordConnection;

    protected $fillable = [
        'name',
        'slug',
        'permissions',
        'tenant_id'
    ];

    public function tenant(): BelongsTo {
        return $this->belongsTo(Tenant::class);
    }
}
