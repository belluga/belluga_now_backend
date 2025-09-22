<?php

namespace App\Support\Helpers;

class ArrayReplaceEmptyAware
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

    public static function mergeIfOverridenIsNotEmptyRecursive(array $mainArray, ?array $overrideArray): array
    {
        if($overrideArray === null){
            return $mainArray;
        }

        foreach ($overrideArray as $key => $value) {
            // Handle recursion by calling itself
            if (isset($mainArray[$key]) && is_array($mainArray[$key]) && is_array($value)) {
                $mainArray[$key] = self::mergeIfOverridenIsNotEmptyRecursive($mainArray[$key], $value);
            }
            // The new condition: check if the NEW value is not empty
            else if ($value !== null && $value !== '') {
                $mainArray[$key] = $value;
            }
            // If the new value is empty, do nothing.
        }
        return $mainArray;
    }
}
