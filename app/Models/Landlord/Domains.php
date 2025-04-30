<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MongoDB\Laravel\Eloquent\Casts\ObjectId;
use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Domains extends Model
{
    use UsesLandlordConnection;

    protected $fillable = [
        'type',
        'path',
    ];

    public function tenant(): BelongsTo {
        return $this->belongsTo(Tenant::class);
    }

    protected $casts = [
        '_id' => ObjectId::class,
        'tenant_id' => ObjectId::class,
    ];
}
