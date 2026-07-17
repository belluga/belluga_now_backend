<?php

declare(strict_types=1);

namespace App\Application\Auth;

final readonly class PhoneIdentityCoordinationLease
{
    /**
     * @param  array<int, string>  $phoneHashes
     */
    public function __construct(
        public array $phoneHashes,
        public string $ownerToken,
        public string $operation,
    ) {}
}
