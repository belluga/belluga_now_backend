<?php

namespace App\Models\Landlord;

use App\Casts\LogoSettingsCast;
use App\Casts\PwaIconCast;
use App\Casts\ThemeDataSettingsCast;
use MongoDB\Laravel\Eloquent\Model;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

/**
 * Representa o documento aninhado 'branding_data'.
 */
class BrandingData extends Model
{
    use UsesLandlordConnection;

    protected static $unguarded = true;

    protected $casts = [
        'theme_data_settings' => ThemeDataSettingsCast::class,
        'logo_settings' => LogoSettingsCast::class,
        'pwa_icon' => PwaIconCast::class,
    ];

    public function toArray(): array
    {
        return [
            "theme_data_settings" => $this->theme_data_settings->toArray(),
            "logo_settings" => $this->logo_settings->toArray(),
            "pwa_icon" => $this->pwa_icon->toArray(),
        ];
    }
}
