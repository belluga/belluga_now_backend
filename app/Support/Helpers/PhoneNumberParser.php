<?php

namespace App\Support\Helpers;

use Propaganistas\LaravelPhone\PhoneNumber;

class PhoneNumberParser
{
    public static function parse($phone): string
    {
        $validated_phone = null;

        try {
            $validated_phone = new PhoneNumber($phone, 'BR');
        } catch (\Throwable $e) {
            try {
                $validated_phone = new PhoneNumber($phone);
            } catch (\Throwable $e) {
            }
        }

        if ($validated_phone) {
            $validated_phone = $validated_phone->formatE164();
        }

        return $validated_phone;
    }
}
