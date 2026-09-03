<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

final class AccountProfileExternalLinksCapabilityDisabledException extends ValidationException
{
    public const ERROR_CODE = 'account_profile_external_links_capability_disabled';

    public static function create(): self
    {
        return self::withMessages([
            'external_links' => ['External links are not enabled for this profile type.'],
        ]);
    }
}
