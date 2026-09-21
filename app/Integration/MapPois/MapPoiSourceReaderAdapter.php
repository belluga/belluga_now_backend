<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Application\Accounts\AccountPublicationStateService;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
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

    public function isParentAccountPublished(object $profile): bool
    {
        $accountId = trim((string) ($profile->account_id ?? ''));
        if ($accountId === '') {
            return false;
        }

        $account = Account::query()->find($accountId);

        return $account !== null
            && data_get($account->getAttribute('publication'), 'status')
                === AccountPublicationStateService::PUBLISHED;
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

    public function allTrashedAccountProfileIds(?\DateTimeInterface $deletedSince = null): iterable
    {
        $query = AccountProfile::onlyTrashed()
            ->orderBy('deleted_at')
            ->orderBy('_id');
        if ($deletedSince !== null) {
            $query->where('deleted_at', '>=', $deletedSince);
        }

        foreach ($query->cursor() as $profile) {
            if (! isset($profile->_id)) {
                continue;
            }

            yield (string) $profile->_id;
        }
    }

}
