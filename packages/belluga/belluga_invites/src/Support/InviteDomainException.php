<?php

declare(strict_types=1);

namespace Belluga\Invites\Support;

use RuntimeException;

class InviteDomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus = 422,
        string $message = '',
        public readonly array $payload = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
