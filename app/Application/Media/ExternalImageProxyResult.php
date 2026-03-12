<?php

declare(strict_types=1);

namespace App\Application\Media;

final readonly class ExternalImageProxyResult
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {}
}
