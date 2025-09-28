<?php


namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait ConvertSnakeToCamel
{
    /**
     * Recursively convert the keys of the request data to camelCase.
     */
    protected function convertKeysToCamelCase(array $data): array
    {
        $convertedArray = [];

        foreach ($data as $key => $value) {
            $convertedKey = Str::camel($key);

            $convertedArray[$convertedKey] = is_array($value)
                ? $this->recursiveConvertKeys($value)
                : $value;
        }

        return $convertedArray;
    }

    /**
     * Helper for recursive conversion.
     */
    private function recursiveConvertKeys(array $data): array
    {
        $convertedArray = [];
        foreach ($data as $key => $value) {
            $convertedKey = Str::camel($key);
            $convertedArray[$convertedKey] = is_array($value)
                ? $this->recursiveConvertKeys($value)
                : $value;
        }
        return $convertedArray;
    }
}
