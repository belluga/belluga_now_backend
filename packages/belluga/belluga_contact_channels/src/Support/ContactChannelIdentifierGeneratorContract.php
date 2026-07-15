<?php

declare(strict_types=1);

namespace Belluga\ContactChannels\Support;

interface ContactChannelIdentifierGeneratorContract
{
    public function generate(): string;
}
