<?php

declare(strict_types=1);

namespace Belluga\MapPois\Contracts;

use MongoDB\Database;
use MongoDB\Driver\Session;

/**
 * Lets the MapPoi projection owner refresh one live source inside that
 * source's existing transaction. The explicit methods intentionally keep this
 * limited to the two sources covered by the lifecycle invariant.
 */
interface MapPoiSourceRefreshContract
{
    /** @param callable(object, Database, Session): void $persist */
    public function refreshLiveAccountProfile(string $profileId, callable $persist): bool;

    /** @param callable(object, Database, Session): void $persist */
    public function refreshLiveEvent(string $eventId, callable $persist): bool;
}
