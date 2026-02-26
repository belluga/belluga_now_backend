<?php

declare(strict_types=1);

namespace Belluga\Events\Capabilities;

use Belluga\Events\Contracts\EventCapabilityHandlerContract;
use Belluga\Events\Contracts\EventCapabilityRegistryContract;
use RuntimeException;

class InMemoryEventCapabilityRegistry implements EventCapabilityRegistryContract
{
    /**
     * @var array<string, EventCapabilityHandlerContract>
     */
    private array $handlers = [];

    public function register(EventCapabilityHandlerContract $handler): void
    {
        $key = $handler->key();

        if (isset($this->handlers[$key])) {
            throw new RuntimeException("Duplicate event capability handler [{$key}].");
        }

        $this->handlers[$key] = $handler;
    }

    public function all(): array
    {
        return array_values($this->handlers);
    }

    public function find(string $key): ?EventCapabilityHandlerContract
    {
        return $this->handlers[$key] ?? null;
    }
}

