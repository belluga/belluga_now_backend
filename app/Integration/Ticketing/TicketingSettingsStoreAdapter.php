<?php

declare(strict_types=1);

namespace App\Integration\Ticketing;

use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Ticketing\Contracts\TicketingSettingsStoreContract;

class TicketingSettingsStoreAdapter implements TicketingSettingsStoreContract
{
    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
    ) {}

    public function getNamespaceValue(string $namespace): array
    {
        return $this->settingsStore->getNamespaceValue('tenant', $namespace);
    }
}
