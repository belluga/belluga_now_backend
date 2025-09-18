<?php

namespace App\Casts;

use App\DataObjects\Branding\PwaIcon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

class PwaIconCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): ?PwaIcon
    {
        if (is_null($value)) {
            return null;
        }

        $data = (array) $value;
        return PwaIcon::fromArray($data);
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if(is_array($value)) {
            return ["pwa_icon" => $value];
        }

        if($value instanceof PwaIcon){
            return ["pwa_icon" => $value->toArray()];
        }

        throw new InvalidArgumentException('The given value must be a PwaIcon instance or an array.');
    }
}
