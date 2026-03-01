<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\StaticAsset;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\MapPois\Contracts\MapPoiSourceReaderContract;
use MongoDB\BSON\ObjectId;

class MapPoiSourceReaderAdapter implements MapPoiSourceReaderContract
{
    public function findEventById(string $eventId): ?object
    {
        $event = Event::query()->find($eventId);
        if ($event) {
            return $event;
        }

        try {
            return Event::query()->find(new ObjectId($eventId));
        } catch (\Throwable) {
            return null;
        }
    }

    public function findPublishedOccurrencesForEvent(string $eventId): array
    {
        return EventOccurrence::query()
            ->where('event_id', $eventId)
            ->where('is_event_published', true)
            ->orderBy('starts_at')
            ->get()
            ->all();
    }

    public function findAccountProfileById(string $profileId): ?object
    {
        return AccountProfile::query()->find($profileId);
    }

    public function findStaticAssetById(string $assetId): ?object
    {
        return StaticAsset::query()->find($assetId);
    }

    public function allEventIds(): iterable
    {
        foreach (Event::query()->whereNull('deleted_at')->orderBy('_id')->cursor() as $event) {
            if (! isset($event->_id)) {
                continue;
            }

            yield (string) $event->_id;
        }
    }

    public function allAccountProfileIds(): iterable
    {
        foreach (AccountProfile::query()->whereNull('deleted_at')->orderBy('_id')->cursor() as $profile) {
            if (! isset($profile->_id)) {
                continue;
            }

            yield (string) $profile->_id;
        }
    }

    public function allStaticAssetIds(): iterable
    {
        foreach (StaticAsset::query()->whereNull('deleted_at')->orderBy('_id')->cursor() as $asset) {
            if (! isset($asset->_id)) {
                continue;
            }

            yield (string) $asset->_id;
        }
    }
}
