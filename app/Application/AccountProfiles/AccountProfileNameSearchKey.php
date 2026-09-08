<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

final class AccountProfileNameSearchKey
{
    public static function fromDisplayName(string $displayName): string
    {
        return AccountProfileSearchV1::normalize($displayName);
    }

    public static function normalizeRequestSearch(mixed $rawSearch): ?string
    {
        return AccountProfileSearchV1::normalizeRequestSearch($rawSearch);
    }
}
