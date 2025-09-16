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

        return ThemeDataSettings::fromArray((array) $value);
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if ($value instanceof ThemeDataSettings) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return $value;
        }

        throw new InvalidArgumentException('The given value must be a ThemeDataSettings instance or an array.');
    }
}
