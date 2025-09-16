<?php

namespace App\Casts;

use App\DataObjects\Branding\LogoSettings;
use App\DataObjects\Branding\ThemeDataSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

class LogoSettingsCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?LogoSettings
    {
        if (is_null($value)) {
            return null;
        }

        // The $value is already an array, but it's missing pwa_icon.
        // We pass it directly to the Data Object.
        return LogoSettings::fromArray((array) $value);
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if ($value instanceof LogoSettings) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return $value;
        }

        throw new InvalidArgumentException('The given value must be a LogoSettings instance or an array.');
    }
}
