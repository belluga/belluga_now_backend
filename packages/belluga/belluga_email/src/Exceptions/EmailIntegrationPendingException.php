<?php

declare(strict_types=1);

namespace Belluga\Email\Exceptions;

use RuntimeException;

class EmailIntegrationPendingException extends RuntimeException
{
    /**
     * @param  array<int, string>  $missingFields
     */
    public function __construct(
        public readonly array $missingFields = [],
    ) {
        parent::__construct('Integracao de email pendente. Informe ao administrador do site.');
    }
}
