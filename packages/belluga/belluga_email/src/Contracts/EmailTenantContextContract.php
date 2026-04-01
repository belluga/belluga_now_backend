<?php

declare(strict_types=1);

namespace Belluga\Email\Contracts;

interface EmailTenantContextContract
{
    public function currentTenantDisplayName(): ?string;
}
