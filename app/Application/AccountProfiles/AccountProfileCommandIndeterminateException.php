<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use RuntimeException;

final class AccountProfileCommandIndeterminateException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'The Account Profile command outcome could not be confirmed. Retry with the same X-Request-Id.',
            previous: $previous,
        );
    }
}
