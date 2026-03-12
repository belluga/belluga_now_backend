<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Contracts;

interface TicketingPolicyContract
{
    public function isTicketingEnabled(): bool;

    /**
     * Returns one of: `auth_only`, `guest_or_auth`.
     */
    public function identityMode(): string;
}
