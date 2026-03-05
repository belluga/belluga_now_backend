<?php

declare(strict_types=1);

namespace Belluga\Settings\Support;

use Traversable;

final class BsonNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(mixed $value): array
    {
        $normalized = self::toNative($value);

        return is_array($normalized) ? $normalized : [];
    }

    public static function toNative(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $value = $value->getArrayCopy();
        }

        if ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = self::toNative($item);
        }

        return $normalized;
    }
}
