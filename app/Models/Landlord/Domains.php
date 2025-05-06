<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Domains extends Model
{
    use UsesLandlordConnection;

    public function tenant(): BelongsTo {
        return $this->belongsTo(Tenant::class);
    }
}
