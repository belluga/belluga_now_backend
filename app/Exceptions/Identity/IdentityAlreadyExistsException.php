<?php

declare(strict_types=1);

namespace App\Exceptions\Identity;

use RuntimeException;

class IdentityAlreadyExistsException extends RuntimeException
{
    /**
     * @param  array<int, string>  $emails
     */
    public function __construct(private readonly array $emails)
    {
        parent::__construct('Identity already exists for provided contact points.');
    }

    /**
     * @return array<int, string>
     */
    public function emails(): array
    {
        return $this->emails;
    }
}
