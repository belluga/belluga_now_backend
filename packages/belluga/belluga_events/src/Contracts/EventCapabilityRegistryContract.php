<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventCapabilityRegistryContract
{
    public function register(EventCapabilityHandlerContract $handler): void;

    /**
     * @return array<int, EventCapabilityHandlerContract>
     */
    public function all(): array;

    public function find(string $key): ?EventCapabilityHandlerContract;
}

