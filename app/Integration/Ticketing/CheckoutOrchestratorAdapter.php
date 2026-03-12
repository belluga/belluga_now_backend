<?php

declare(strict_types=1);

namespace App\Integration\Ticketing;

use Belluga\Settings\Contracts\SettingsStoreContract;
use Belluga\Ticketing\Contracts\CheckoutOrchestratorContract;

class CheckoutOrchestratorAdapter implements CheckoutOrchestratorContract
{
    public function __construct(
        private readonly SettingsStoreContract $settingsStore,
    ) {}

    public function isEnabled(): bool
    {
        $values = $this->settingsStore->getNamespaceValue('tenant', 'checkout_ticketing');

        return (bool) ($values['enabled'] ?? false);
    }

    public function beginCheckout(array $payload, string $idempotencyKey): array
    {
        $mode = strtolower((string) ($payload['checkout_mode'] ?? 'free'));

        if (! in_array($mode, ['free', 'paid'], true)) {
            return [
                'status' => 'rejected',
                'code' => 'invalid_checkout_mode',
                'idempotency_key' => $idempotencyKey,
            ];
        }

        if ($mode === 'paid' && ! $this->isEnabled()) {
            return [
                'status' => 'integration_unavailable',
                'code' => 'paid_mode_deferred',
                'idempotency_key' => $idempotencyKey,
            ];
        }

        return [
            'status' => 'accepted',
            'mode' => $mode,
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
        ];
    }
}
