<?php

declare(strict_types=1);

namespace Belluga\ContactChannels;

final readonly class ContactChannelNormalizationResult
{
    /**
     * @param array<int, array<string, mixed>> $channels
     * @param array<string, string> $draftKeyToChannelId
     */
    public function __construct(
        public array $channels,
        public array $draftKeyToChannelId,
    ) {}
}
