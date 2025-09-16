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
    // Informa que este model é embutido e pode ser preenchido em massa.
    protected static $unguarded = true;

    /**
     * Usamos casts para transformar os sub-objetos em DTOs automaticamente.
     * Isso organiza o código e garante a estrutura dos dados.
     */
    protected $casts = [
        'theme_data_settings' => ThemeDataSettingsCast::class,
        'logo_settings' => LogoSettingsCast::class,
        'pwa_icon' => PwaIconCast::class,
    ];

    public function toArray(): array
    {
        return [
            ...$this->theme_data_settings->toArray(),
            ...$this->logo_settings->toArray(),
            ...$this->pwa_icon->toArray(),
        ];
    }
}
