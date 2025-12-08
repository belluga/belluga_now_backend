<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Domains extends Model
{
    use UsesLandlordConnection, SoftDeletes;

    protected $fillable = [
        'path',
        'type'
    ];

    public function tenant(): BelongsTo {
        return $this->belongsTo(Tenant::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Domains $domain) {
            if ($domain->path) {
                $domain->path = Str::lower(trim($domain->path));
            }
        });
    }
}
