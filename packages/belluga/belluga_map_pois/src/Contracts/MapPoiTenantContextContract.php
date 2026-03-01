<?php

declare(strict_types=1);

namespace Belluga\MapPois\Contracts;

interface MapPoiTenantContextContract
{
    public function currentTenantId(): ?string;
}
