<?php

namespace App\Support\Helpers;

class ArrayReplaceIfEmpty
{
    public static function mergeIfEmptyRecursive(array $mainArray, array $overrideArray): array
    {
        foreach ($overrideArray as $key => $value) {
            if (isset($mainArray[$key]) && is_array($mainArray[$key]) && is_array($value)) {
                $mainArray[$key] = self::mergeIfEmptyRecursive($mainArray[$key], $value);
            } else if (!isset($mainArray[$key]) || is_null($mainArray[$key]) || $mainArray[$key] === '') {
                $mainArray[$key] = $value;
            }
        }

        return $mainArray;
    }
}
