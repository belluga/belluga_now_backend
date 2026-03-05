<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventTenantContextContract
{
    public function resolveCurrentTenantId(): ?string;
}
