<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Contracts;

interface PushAccountContextContract
{
    public function currentAccountId(): ?string;
}
