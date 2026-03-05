<?php

declare(strict_types=1);

namespace Belluga\MapPois\Contracts;

interface MapPoiSourceReaderContract
{
    public function findEventById(string $eventId): ?object;

    /**
     * @return array<int, object>
     */
    public function findPublishedOccurrencesForEvent(string $eventId): array;

    public function findAccountProfileById(string $profileId): ?object;

    public function findStaticAssetById(string $assetId): ?object;

    /**
     * @return iterable<string>
     */
    public function allEventIds(): iterable;

    /**
     * @return iterable<string>
     */
    public function allAccountProfileIds(): iterable;

    /**
     * @return iterable<string>
     */
    public function allStaticAssetIds(): iterable;
}
