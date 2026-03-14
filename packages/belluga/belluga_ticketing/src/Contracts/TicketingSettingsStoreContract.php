<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Contracts;

interface TicketingSettingsStoreContract
{
    /**
     * @return array<string, mixed>
     */
    public function getNamespaceValue(string $namespace): array;
}
