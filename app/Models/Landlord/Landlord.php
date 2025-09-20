<?php

namespace App\Models\Landlord;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Cache;
use MongoDB\Laravel\Eloquent\Model;
use MongoDB\Laravel\Relations\EmbedsOne;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Landlord extends Model
{
    use HasFactory, UsesLandlordConnection;

    protected $fillable = ['name'];
    protected $casts = [];

    public function brandingData(): EmbedsOne
    {
        return $this->embedsOne(BrandingData::class, 'branding_data');
    }

    protected const CACHE_KEY = 'landlord:singleton';

    public static function singleton(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return static::query()->with('brandingData')->firstOrFail();
        });
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function getManifestData(): array {

        return [
            'name'             => $this->name,
            'short_name'       => $this->name,
            'description'      => $this->description,
            'start_url'        => '/',
            'display'          => 'standalone',
            'background_color' => $this->brandingData->toArray()["theme_data_settings"]['light_scheme_data']['primary_seed_color'],
            'theme_color'      => $this->brandingData->toArray()["theme_data_settings"]['light_scheme_data']['primary_seed_color']
        ];
    }
}
