<?php

declare(strict_types=1);

namespace Belluga\Media\Contracts;

interface TenantMediaScopeResolverContract
{
    public function resolveTenantScope(?string $baseUrl): ?string;
}
