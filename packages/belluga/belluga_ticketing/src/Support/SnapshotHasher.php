<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Support;

class SnapshotHasher
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::sortRecursive($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sortRecursive(mixed $value): mixed
    {
        if (is_array($value)) {
            if (self::isAssoc($value)) {
                ksort($value);
            }

            foreach ($value as $key => $item) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
