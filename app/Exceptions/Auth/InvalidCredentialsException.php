<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('As credenciais fornecidas estão incorretas.');
    }
}
