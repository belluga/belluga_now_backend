<?php

namespace App\Casts;

use App\DataObjects\Branding\ThemeDataSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

class ThemeDataSettingsCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?ThemeDataSettings
    {
        if (is_null($value)) {
            return null;
        }

        $data = (array) $value;
        return ThemeDataSettings::fromArray($data);
    }

    public function set($model, string $key, $value, array $attributes): array
    {

        if(is_array($value)) {
            return ["theme_data_settings" => $value];
        }

        if($value instanceof ThemeDataSettings){
            return ["theme_data_settings" => $value->toArray()];
        }

        throw new InvalidArgumentException('The given value must be a ThemeDataSettings instance or an array.');

    }
}
