<?php

declare(strict_types=1);

namespace App\DataObjects\Settings;

class SettingsPayload
{
    /**
     * @return array<int, mixed>|array<string, mixed>
     */
    public static function toArray(mixed $value): array
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return $value->getArrayCopy();
        }
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return [];
    }
}
