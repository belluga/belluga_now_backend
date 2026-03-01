<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Application\Settings;

use Belluga\Settings\Contracts\SettingsStoreContract;

class TicketingRuntimeSettingsService
{
    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
    ) {
    }

    public function isTicketingEnabled(): bool
    {
        $core = $this->settingsStore->getNamespaceValue('tenant', 'ticketing_core');

        return (bool) ($core['enabled'] ?? false);
    }

    public function identityMode(): string
    {
        $core = $this->settingsStore->getNamespaceValue('tenant', 'ticketing_core');
        $mode = (string) ($core['identity_mode'] ?? 'auth_only');

        return in_array($mode, ['auth_only', 'guest_or_auth'], true) ? $mode : 'auth_only';
    }

    public function queueMode(): string
    {
        $queue = $this->settingsStore->getNamespaceValue('tenant', 'ticketing_hold_queue');
        $mode = (string) ($queue['policy'] ?? 'auto');

        return in_array($mode, ['auto', 'off'], true) ? $mode : 'auto';
    }

    public function maxPerPrincipal(): int
    {
        $queue = $this->settingsStore->getNamespaceValue('tenant', 'ticketing_hold_queue');

        return $this->clampInt($queue['max_per_principal'] ?? 10, 1, 100);
    }

    public function defaultHoldMinutes(): int
    {
        $queue = $this->settingsStore->getNamespaceValue('tenant', 'ticketing_hold_queue');

        return $this->clampInt($queue['default_hold_minutes'] ?? 10, 1, 120);
    }

    public function resolveHoldMinutes(?int $eventOverrideMinutes): int
    {
        if ($eventOverrideMinutes !== null) {
            return $this->clampInt($eventOverrideMinutes, 1, 120);
        }

        return $this->defaultHoldMinutes();
    }

    public function defaultCheckoutMode(): string
    {
        $checkout = $this->settingsStore->getNamespaceValue('tenant', 'checkout_core');
        $mode = (string) ($checkout['mode'] ?? 'free');

        return in_array($mode, ['free', 'paid'], true) ? $mode : 'free';
    }

    public function promotionsEnabled(): bool
    {
        $promotions = $this->settingsStore->getNamespaceValue('tenant', 'ticketing_promotions');

        return (bool) ($promotions['enabled'] ?? false);
    }

    public function allowTransferReissue(): bool
    {
        $lifecycle = $this->settingsStore->getNamespaceValue('tenant', 'ticketing_lifecycle');

        return (bool) ($lifecycle['allow_transfer_reissue'] ?? false);
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $number));
    }
}
