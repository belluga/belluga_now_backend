<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Domains extends Model
{
    use UsesLandlordConnection, SoftDeletes;

    protected $fillable = [
        'path'
    ];

    public function tenant(): BelongsTo {
        return $this->belongsTo(Tenant::class);
    }
}
