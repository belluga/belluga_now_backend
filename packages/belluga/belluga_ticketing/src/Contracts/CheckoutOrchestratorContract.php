<?php

declare(strict_types=1);

namespace Belluga\Ticketing\Contracts;

interface CheckoutOrchestratorContract
{
    public function isEnabled(): bool;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function beginCheckout(array $payload, string $idempotencyKey): array;
}
