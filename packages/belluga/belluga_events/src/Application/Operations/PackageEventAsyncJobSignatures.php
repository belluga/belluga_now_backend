<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Operations;

use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;

class PackageEventAsyncJobSignatures implements EventAsyncJobSignaturesContract
{
    public function signatures(): array
    {
        return [
            'Belluga\\Events\\',
        ];
    }
}
