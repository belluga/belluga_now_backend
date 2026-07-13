<?php

declare(strict_types=1);

namespace Belluga\ContactChannels\Support;

final readonly class ContactChannelLaunchResolution
{
    public function __construct(
        public ?string $uri,
    ) {}

    public function isResolvable(): bool
    {
        return $this->uri !== null;
    }
}
