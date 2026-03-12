<?php

namespace App\Casts;

use App\DataObjects\Branding\LogoSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

class LogoSettingsCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?LogoSettings
    {
        if (is_null($value)) {
            return null;
        }

        $data = (array) $value;

        return LogoSettings::fromArray($data);
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if (is_array($value)) {
            return ['logo_settings' => $value];
        }

        if ($value instanceof LogoSettings) {
            return ['logo_settings' => $value->toArray()];
        }

        throw new InvalidArgumentException('The given value must be a LogoSettings instance or an array.');
    }
}
