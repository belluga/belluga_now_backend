<?php

declare(strict_types=1);

namespace App\Integration\Events;

use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;

class HostEventAsyncJobSignaturesAdapter implements EventAsyncJobSignaturesContract
{
    public function signatures(): array
    {
        return [
            'Belluga\\Events\\',
            'App\\Jobs\\MapPois\\UpsertMapPoiFromEventJob',
            'App\\Jobs\\MapPois\\DeleteMapPoiByRefJob',
        ];
    }
}

