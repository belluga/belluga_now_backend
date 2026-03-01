<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

interface PushTenantContextContract
{
    public function currentTenantId(): ?string;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function runForTenantSlug(string $tenantSlug, callable $callback): mixed;
}

