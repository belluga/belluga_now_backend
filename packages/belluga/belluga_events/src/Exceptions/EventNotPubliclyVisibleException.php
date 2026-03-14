<?php

declare(strict_types=1);

namespace Belluga\Events\Exceptions;

use RuntimeException;

class EventNotPubliclyVisibleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Event not found.');
    }
}
