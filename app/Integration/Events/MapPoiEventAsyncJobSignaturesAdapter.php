<?php

declare(strict_types=1);

namespace App\Integration\Events;

use Belluga\Events\Contracts\EventAsyncJobSignaturesContract;

class MapPoiEventAsyncJobSignaturesAdapter implements EventAsyncJobSignaturesContract
{
    public function signatures(): array
    {
        return [
            'Belluga\\Events\\',
            'Belluga\\MapPois\\Jobs\\UpsertMapPoiFromEventJob',
            'Belluga\\MapPois\\Jobs\\RefreshExpiredEventMapPoisJob',
            'Belluga\\MapPois\\Jobs\\DeleteMapPoiByRefJob',
        ];
    }
}
