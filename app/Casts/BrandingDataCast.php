<?php

namespace App\Casts;

use App\DataObjects\Branding\BrandingData;
use App\DataObjects\Branding\ColorSchemeData;
use App\DataObjects\Branding\LogoSettings;
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
            themeDataSettings: new ThemeDataSettings(
                darkSchemeData: ColorSchemeData::fromArray((array) $data['themeDataSettings']['darkSchemeData']),
                lightSchemeData: ColorSchemeData::fromArray((array) $data['themeDataSettings']['lightSchemeData'])
            ),
            logoSettings: new LogoSettings(...(array) $data['logoSettings'])
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
