<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Exceptions;

use RuntimeException;

class MultiplePushCredentialsException extends RuntimeException
{
    public function __construct(int $count)
    {
        parent::__construct('Multiple push credentials found (' . $count . '). Keep only one credential per tenant.');
    }
}
