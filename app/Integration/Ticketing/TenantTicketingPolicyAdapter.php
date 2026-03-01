<?php

declare(strict_types=1);

namespace App\Integration\Ticketing;

use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Ticketing\Contracts\TicketingPolicyContract;

class TenantTicketingPolicyAdapter implements TicketingPolicyContract
{
    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
    ) {
    }

    public function isTicketingEnabled(): bool
    {
        $values = $this->settingsStore->getNamespaceValue('tenant', 'ticketing_core');

        return (bool) ($values['enabled'] ?? false);
    }

    public function identityMode(): string
    {
        $values = $this->settingsStore->getNamespaceValue('tenant', 'ticketing_core');
        $mode = (string) ($values['identity_mode'] ?? 'auth_only');

        return in_array($mode, ['auth_only', 'guest_or_auth'], true) ? $mode : 'auth_only';
    }
}

