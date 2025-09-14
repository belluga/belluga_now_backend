<?php

namespace App\Casts;

use App\DataObjects\Branding\BrandingData;
use App\DataObjects\Branding\ColorSchemeData;
use App\DataObjects\Branding\LogoSettings;
use App\DataObjects\Branding\PwaIcon;
use App\DataObjects\Branding\ThemeDataSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

class BrandingDataCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return \App\DataObjects\Branding\BrandingData|null
     */
    public function get($model, string $key, $value, array $attributes): ?BrandingData
    {
        if (is_null($value)) {
            return null;
        }

        // MongoDB driver might return an array or an object
        $data = is_string($value) ? json_decode($value, true) : (array) $value;

        return new BrandingData(
            theme_data_settings: new ThemeDataSettings(
                dark_scheme_data: ColorSchemeData::fromArray((array) $data['theme_data_settings']['dark_scheme_data']),
                light_scheme_data: ColorSchemeData::fromArray((array) $data['theme_data_settings']['light_scheme_data'])
            ),
            logo_settings: new LogoSettings(
                favicon_uri: $data['logo_settings']['favicon_uri'] ?? '',
                light_logo_uri: $data['logo_settings']['light_logo_uri'] ?? '',
                dark_logo_uri: $data['logo_settings']['dark_logo_uri'] ?? '',
                light_icon_uri: $data['logo_settings']['light_icon_uri'] ?? '',
                dark_icon_uri: $data['logo_settings']['dark_icon_uri'] ?? '',
                pwa_icon: PwaIcon::fromArray($data['logo_settings']['pwa_icon'])
            )
        );
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return array
     */
    public function set($model, string $key, $value, array $attributes): array
    {
        if (!$value instanceof BrandingData) {
            throw new InvalidArgumentException('The given value is not a BrandingData instance.');
        }

        return ['branding_data' => $value->toArray()];
    }
}
