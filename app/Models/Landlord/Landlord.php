<?php

namespace App\Models\Landlord;

use App\Casts\BrandingDataCast;
use App\Traits\HaveBranding;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Landlord extends Model
{
    use HasFactory, UsesLandlordConnection, HaveBranding;

    protected $fillable = [
        'name'
    ];

    protected $casts = [];

    /**
     * Cache key for the singleton landlord.
     */
    protected const CACHE_KEY = 'landlord:singleton';

    /**
     * Retrieve the single Landlord instance from cache or database.
     *
     * This will:
     * - Cache the instance forever
     * - Throw if no landlord exists
     */
    public static function singleton(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            // Adjust query if you have a specific identifier or status
            return static::query()->firstOrFail();
        });
    }

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::forget(self::CACHE_KEY);
        });

        static::deleted(function () {
            Cache::forget(self::CACHE_KEY);
        });
    }
}
