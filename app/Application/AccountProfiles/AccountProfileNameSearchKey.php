<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Illuminate\Support\Str;

final class AccountProfileNameSearchKey
{
    private const REQUEST_RAW_MIN_SCALARS = 2;

    private const REQUEST_RAW_MAX_SCALARS = 400;

    private const REQUEST_KEY_MIN_SCALARS = 2;

    private const REQUEST_KEY_MAX_SCALARS = 100;

    public static function fromDisplayName(string $displayName): string
    {
        if (! mb_check_encoding($displayName, 'UTF-8')) {
            return '';
        }

        $normalized = \Normalizer::normalize($displayName, \Normalizer::FORM_KD);
        if (! is_string($normalized)) {
            return '';
        }

        $withoutCombiningMarks = preg_replace('/\p{Mn}+/u', '', $normalized);
        if (! is_string($withoutCombiningMarks)) {
            return '';
        }

        $collapsedWhitespace = preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($withoutCombiningMarks)));

        return trim(is_string($collapsedWhitespace) ? $collapsedWhitespace : '');
    }

    public static function normalizeRequestSearch(mixed $rawSearch): ?string
    {
        if (! is_string($rawSearch) || ! mb_check_encoding($rawSearch, 'UTF-8')) {
            return null;
        }

        $rawScalarLength = mb_strlen($rawSearch, 'UTF-8');
        if ($rawScalarLength < self::REQUEST_RAW_MIN_SCALARS || $rawScalarLength > self::REQUEST_RAW_MAX_SCALARS) {
            return null;
        }

        $key = self::fromDisplayName($rawSearch);
        $keyScalarLength = strlen($key);

        return $keyScalarLength >= self::REQUEST_KEY_MIN_SCALARS
            && $keyScalarLength <= self::REQUEST_KEY_MAX_SCALARS
            ? $key
            : null;
    }
}
