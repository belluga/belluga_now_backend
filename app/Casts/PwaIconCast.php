<?php

namespace App\Casts;

use App\DataObjects\Branding\LogoSettings;
use App\DataObjects\Branding\PwaIcon;
use App\DataObjects\Branding\ThemeDataSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

class PwaIconCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?PwaIcon
    {
        if (is_null($value)) {
            return null;
        }
        return PwaIcon::fromArray((array) $value);
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if ($value instanceof PwaIcon) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return $value;
        }

        throw new InvalidArgumentException('The given value must be a PwaIcon instance or an array.');
    }
}
