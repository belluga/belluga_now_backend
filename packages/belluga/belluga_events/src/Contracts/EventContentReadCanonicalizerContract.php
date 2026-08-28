<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventContentReadCanonicalizerContract
{
    public function canonicalize(mixed $value, string $resourceId, string $field): string;
}
