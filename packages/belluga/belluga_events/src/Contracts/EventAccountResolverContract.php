<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventAccountResolverContract
{
    public function resolveAccountIdBySlug(string $accountSlug): string;
}
