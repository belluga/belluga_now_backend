<?php

declare(strict_types=1);

namespace Belluga\ContactChannels;

use DomainException;

final class ContactChannelValidationException extends DomainException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
