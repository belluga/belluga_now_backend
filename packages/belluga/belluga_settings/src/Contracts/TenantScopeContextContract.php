<?php

declare(strict_types=1);

namespace Belluga\Settings\Contracts;

interface TenantScopeContextContract
{
    /**
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    public function runForTenantSlug(string $tenantSlug, callable $callback): mixed;
}
