<?php

declare(strict_types=1);

namespace Belluga\ContactChannels\Support;

final class RandomContactChannelIdentifierGenerator implements ContactChannelIdentifierGeneratorContract
{
    public function generate(): string
    {
        return 'contact_'.bin2hex(random_bytes(16));
    }
}
