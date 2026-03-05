<?php

declare(strict_types=1);

namespace Belluga\Events\Contracts;

interface EventAsyncJobSignaturesContract
{
    /**
     * @return array<int, string>
     */
    public function signatures(): array;
}

