<?php

declare(strict_types=1);

namespace App\Integration\MapPois;

use App\Application\AccountProfiles\AccountProfileTransactionContext;
use App\Application\AccountProfiles\AccountProfileTransactionRunner;
use App\Models\Tenants\AccountProfile;
use Belluga\Events\Application\Transactions\EventTransactionContext;
use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Events\Models\Tenants\Event;
use Belluga\MapPois\Contracts\MapPoiSourceRefreshContract;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

final class MapPoiSourceRefreshAdapter implements MapPoiSourceRefreshContract
{
    public function __construct(
        private readonly AccountProfileTransactionRunner $profileTransactions,
        private readonly EventTransactionRunner $eventTransactions,
    ) {}

    public function refreshLiveAccountProfile(string $profileId, callable $persist): bool
    {
        $profileId = trim($profileId);
        if ($profileId === '') {
            return false;
        }

        return $this->profileTransactions->run(function (AccountProfileTransactionContext $context) use ($profileId, $persist): bool {
            $profile = AccountProfile::query()->find($profileId);
            if (! $profile instanceof AccountProfile) {
                return false;
            }

            try {
                $id = new ObjectId($profileId);
            } catch (\Throwable) {
                return false;
            }
            $touched = $context->collection('account_profiles')->updateOne(
                ['_id' => $id, 'deleted_at' => null],
                ['$set' => ['updated_at' => new UTCDateTime((int) now()->getTimestampMs())]],
                $context->rawOptions(),
            );
            if ($touched->getMatchedCount() !== 1) {
                return false;
            }

            $persist($profile->fresh() ?? $profile, $context->database(), $context->session());

            return true;
        });
    }

    public function refreshLiveEvent(string $eventId, callable $persist): bool
    {
        $eventId = trim($eventId);
        if ($eventId === '') {
            return false;
        }

        return $this->eventTransactions->run(function (EventTransactionContext $context) use ($eventId, $persist): bool {
            $event = Event::query()->find($eventId);
            if (! $event instanceof Event) {
                return false;
            }

            try {
                $id = new ObjectId($eventId);
            } catch (\Throwable) {
                return false;
            }
            $touched = $context->collection('events')->updateOne(
                ['_id' => $id, 'deleted_at' => null],
                ['$set' => ['updated_at' => new UTCDateTime((int) now()->getTimestampMs())]],
                $context->rawOptions(),
            );
            if ($touched->getMatchedCount() !== 1) {
                return false;
            }

            $persist($event->fresh() ?? $event, $context->database(), $context->session());

            return true;
        });
    }
}
