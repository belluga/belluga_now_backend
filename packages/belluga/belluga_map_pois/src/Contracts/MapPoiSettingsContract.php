<?php

declare(strict_types=1);

namespace Belluga\MapPois\Contracts;

interface MapPoiSettingsContract
{
    /**
     * @return array<string, mixed>
     */
    public function resolveEventsSettings(): array;

    /**
     * @return array<string, mixed>
     */
    public function resolveMapUiSettings(): array;

    /**
     * @return array<string, mixed>
     */
    public function resolveMapIngestSettings(): array;
}
