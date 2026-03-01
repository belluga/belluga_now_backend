<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Support;

use RuntimeException;

class TicketingDomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus = 422,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
