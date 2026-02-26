<?php

declare(strict_types=1);

namespace App\Integration\Events;

use Belluga\Events\Contracts\EventCapabilitySettingsContract;
use Belluga\Settings\Contracts\SettingsRegistryContract;
use Belluga\Settings\Contracts\SettingsStoreContract;

class TenantCapabilitySettingsAdapter implements EventCapabilitySettingsContract
{
    public function __construct(
        private readonly SettingsRegistryContract $registry,
        private readonly SettingsStoreContract $store,
    ) {
    }

    public function resolveTenantCapabilities(): array
    {
        $definition = $this->registry->find('events', 'tenant');
        if (! $definition) {
            return [];
        }

        $eventsSettings = $this->store->getNamespaceValue('tenant', 'events');
        $capabilities = $eventsSettings['capabilities'] ?? [];

        return is_array($capabilities) ? $capabilities : [];
    }
}

