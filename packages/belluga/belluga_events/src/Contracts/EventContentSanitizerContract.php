<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventContentSanitizerContract
{
    public function sanitize(?string $value): string;
}
