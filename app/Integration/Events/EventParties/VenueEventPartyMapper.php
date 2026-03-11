<?php

declare(strict_types=1);

namespace App\Integration\Events\EventParties;

use Belluga\Events\Contracts\EventPartyMapperContract;

class VenueEventPartyMapper implements EventPartyMapperContract
{
    public function partyType(): string
    {
        return 'venue';
    }

    public function defaultCanEdit(): bool
    {
        return true;
    }

    public function mapMetadata(array $source): array
    {
        return [
            'display_name' => isset($source['display_name']) ? (string) $source['display_name'] : null,
        ];
    }
}
