<?php

declare(strict_types=1);

namespace Belluga\ContactChannels\Support;

final readonly class ContactChannelCapabilities
{
    public function __construct(
        public bool $publicCard,
        public bool $directLaunch,
        public bool $bubble,
        public bool $messagePresets,
        public bool $repeatable,
        public int $maxInitialMessages,
        public int $maxInitialMessageCtaLength,
        public int $maxInitialMessageLength,
    ) {}
}
