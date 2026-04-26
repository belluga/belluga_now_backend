<?php

declare(strict_types=1);

namespace Belluga\Events\Application\Events;

use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Events\Support\Validation\InputConstraints;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventBus;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class EventManagementOccurrenceQuery
{
    private const DEFAULT_EVENT_DURATION_MS = 10800000; // 3h

    /**
     * @param  array<string, mixed>  $queryParams
     * @param  array<int, string>  $temporalBuckets
     * @return array{event_ids: array<int, string>, total: int, page: int, per_page: int}
     */
    public function paginateEventIds(
        array $queryParams,
        array $temporalBuckets,
        ?Carbon $specificDate,
        int $perPage,
        bool $isAdminContext,
        ?string $accountContextId
    ): array {
        $page = max(1, (int) Arr::get($queryParams, 'page', 1));
        if (! $isAdminContext) {
            $page = min($page, InputConstraints::PUBLIC_PAGE_MAX);
        }
        $skip = ($page - 1) * $perPage;

        $accountContextId = $this->normalizeAccountContextId($accountContextId);

        $pipeline = $this->buildOccurrenceEventPipeline(
            $queryParams,
            $temporalBuckets,
            $specificDate,
            $isAdminContext,
            $accountContextId
        );

        $facetRows = $this->runAggregate([
            ...$pipeline,
            [
                '$facet' => [
                    'metadata' => [
                        ['$count' => 'total'],
                    ],
                    'data' => [
                        ['$skip' => $skip],
                        ['$limit' => $perPage],
                        ['$project' => ['event_id' => '$_id', 'first_starts_at' => 1]],
                    ],
                ],
            ],
        ], 'management_occurrence_page_with_count');

        $facet = $facetRows[0] ?? [];
        $metadata = is_array($facet['metadata'] ?? null) ? $facet['metadata'] : [];
        $total = (int) ($metadata[0]['total'] ?? 0);
        if ($total === 0) {
            return $this->emptyResult($page, $perPage);
        }

        $rows = is_array($facet['data'] ?? null) ? $facet['data'] : [];
        $eventIds = collect($rows)
            ->map(static fn (mixed $row): string => trim((string) ($row['event_id'] ?? '')))
            ->filter(static fn (string $eventId): bool => $eventId !== '')
            ->values()
            ->all();

        return [
            'event_ids' => $eventIds,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array{event_ids: array<int, string>, total: int, page: int, per_page: int}
     */
    private function emptyResult(int $page, int $perPage): array
    {
        return [
            'event_ids' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @param  array<int, string>  $temporalBuckets
     * @return array<int, array<string, mixed>>
     */
    private function buildOccurrenceEventPipeline(
        array $queryParams,
        array $temporalBuckets,
        ?Carbon $specificDate,
        bool $isAdminContext,
        ?string $accountContextId
    ): array {
        $occurrenceMatch = ['deleted_at' => null];
        if (! $isAdminContext) {
            $occurrenceMatch['is_event_published'] = true;
        }

        if ($this->isOnlyFutureTemporalBucket($temporalBuckets)) {
            $this->applyStartsAtConstraint($occurrenceMatch, [
                '$gt' => new UTCDateTime(Carbon::now()),
            ]);
        } else {
            $exprClauses = $this->temporalExprClauses($temporalBuckets);
            if ($exprClauses !== []) {
                $occurrenceMatch['$expr'] = count($exprClauses) === 1
                    ? $exprClauses[0]
                    : ['$or' => $exprClauses];
            }
        }

        if ($specificDate !== null) {
            $this->applyStartsAtConstraint($occurrenceMatch, [
                '$gte' => new UTCDateTime($specificDate->copy()->startOfDay()),
                '$lt' => new UTCDateTime($specificDate->copy()->addDay()->startOfDay()),
            ]);
        }
        $this->applyOccurrenceProfileFilters($occurrenceMatch, $queryParams, $accountContextId);

        return [
            ['$match' => $occurrenceMatch],
            [
                '$group' => [
                    '_id' => '$event_id',
                    'first_starts_at' => ['$min' => '$starts_at'],
                ],
            ],
            ['$sort' => ['first_starts_at' => $isAdminContext ? 1 : -1, '_id' => 1]],
            [
                '$addFields' => [
                    'event_object_id' => [
                        '$convert' => [
                            'input' => '$_id',
                            'to' => 'objectId',
                            'onError' => null,
                            'onNull' => null,
                        ],
                    ],
                ],
            ],
            [
                '$lookup' => [
                    'from' => 'events',
                    'localField' => 'event_object_id',
                    'foreignField' => '_id',
                    'as' => 'event',
                ],
            ],
            ['$unwind' => '$event'],
            ['$match' => $this->buildEventMatch($queryParams, $isAdminContext, $accountContextId)],
        ];
    }

    /**
     * @param  array<string, mixed>  $occurrenceMatch
     * @param  array<string, mixed>  $queryParams
     */
    private function applyOccurrenceProfileFilters(
        array &$occurrenceMatch,
        array $queryParams,
        ?string $accountContextId
    ): void {
        $clauses = [];

        if ($accountContextId !== null) {
            $clauses[] = ['account_context_ids' => $accountContextId];
        }

        $venueProfileId = $this->extractProfileFilterId($queryParams, 'venue_profile_id');
        if ($venueProfileId !== null) {
            $profileIds = $this->buildProfileIdCandidates($venueProfileId);
            $clauses[] = [
                '$or' => [
                    ['place_ref.id' => ['$in' => $profileIds]],
                    ['place_ref._id' => ['$in' => $profileIds]],
                ],
            ];
        }

        $relatedAccountProfileId = $this->extractProfileFilterId($queryParams, 'related_account_profile_id');
        if ($relatedAccountProfileId !== null) {
            $profileIds = $this->buildProfileIdCandidates($relatedAccountProfileId);
            $clauses[] = [
                'event_parties' => [
                    '$elemMatch' => [
                        'party_type' => ['$ne' => 'venue'],
                        'party_ref_id' => ['$in' => $profileIds],
                    ],
                ],
            ];
        }

        if ($clauses === []) {
            return;
        }

        $existingAnd = is_array($occurrenceMatch['$and'] ?? null)
            ? $occurrenceMatch['$and']
            : [];
        $occurrenceMatch['$and'] = array_values([
            ...$existingAnd,
            ...$clauses,
        ]);
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array<string, mixed>
     */
    private function buildEventMatch(
        array $queryParams,
        bool $isAdminContext,
        ?string $accountContextId
    ): array {
        $match = [
            'event.deleted_at' => null,
            '$and' => [],
        ];

        if (array_key_exists('status', $queryParams) && $queryParams['status'] !== null) {
            $match['event.publication.status'] = $queryParams['status'];
        }

        if ($accountContextId !== null) {
            $match['$and'][] = ['event.account_context_ids' => $accountContextId];
        }

        $venueProfileId = $this->extractProfileFilterId($queryParams, 'venue_profile_id');
        if ($venueProfileId !== null) {
            $profileIds = $this->buildProfileIdCandidates($venueProfileId);
            $match['$and'][] = [
                '$or' => [
                    ['event.place_ref.id' => ['$in' => $profileIds]],
                    ['event.place_ref._id' => ['$in' => $profileIds]],
                ],
            ];
        }

        $relatedAccountProfileId = $this->extractProfileFilterId($queryParams, 'related_account_profile_id');
        if ($relatedAccountProfileId !== null) {
            $profileIds = $this->buildProfileIdCandidates($relatedAccountProfileId);
            $match['$and'][] = [
                'event.event_parties' => [
                    '$elemMatch' => [
                        'party_type' => ['$ne' => 'venue'],
                        'party_ref_id' => ['$in' => $profileIds],
                    ],
                ],
            ];
        }

        if (! $isAdminContext) {
            $now = new UTCDateTime(Carbon::now());
            $match['$and'][] = [
                '$or' => [
                    ['event.publication.status' => 'published'],
                    ['event.publication.status' => null],
                ],
            ];
            $match['$and'][] = [
                '$or' => [
                    ['event.publication.publish_at' => null],
                    ['event.publication.publish_at' => ['$lte' => $now]],
                ],
            ];
        }

        if ($match['$and'] === []) {
            unset($match['$and']);
        }

        return $match;
    }

    /**
     * @param  array<int, string>  $temporalBuckets
     * @return array<int, array<string, mixed>>
     */
    private function temporalExprClauses(array $temporalBuckets): array
    {
        $now = new UTCDateTime(Carbon::now());
        $effectiveEndExpr = [
            '$ifNull' => [
                '$effective_ends_at',
                [
                    '$add' => ['$starts_at', self::DEFAULT_EVENT_DURATION_MS],
                ],
            ],
        ];

        $clauses = [];
        if (in_array('past', $temporalBuckets, true)) {
            $clauses[] = ['$lte' => [$effectiveEndExpr, $now]];
        }
        if (in_array('now', $temporalBuckets, true)) {
            $clauses[] = [
                '$and' => [
                    ['$lte' => ['$starts_at', $now]],
                    ['$gt' => [$effectiveEndExpr, $now]],
                ],
            ];
        }
        if (in_array('future', $temporalBuckets, true)) {
            $clauses[] = ['$gt' => ['$starts_at', $now]];
        }

        return $clauses;
    }

    /**
     * @param  array<int, string>  $temporalBuckets
     */
    private function isOnlyFutureTemporalBucket(array $temporalBuckets): bool
    {
        return count($temporalBuckets) === 1 && in_array('future', $temporalBuckets, true);
    }

    /**
     * @param  array<string, mixed>  $occurrenceMatch
     * @param  array<string, UTCDateTime>  $constraint
     */
    private function applyStartsAtConstraint(array &$occurrenceMatch, array $constraint): void
    {
        $current = $occurrenceMatch['starts_at'] ?? [];
        if (! is_array($current)) {
            $current = [];
        }

        $occurrenceMatch['starts_at'] = array_merge($current, $constraint);
    }

    private function normalizeAccountContextId(?string $accountContextId): ?string
    {
        $normalized = trim((string) ($accountContextId ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function extractProfileFilterId(array $queryParams, string $key): ?string
    {
        $raw = Arr::get($queryParams, $key);
        if (! is_string($raw)) {
            return null;
        }

        $normalized = trim($raw);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pipeline
     * @return array<int, mixed>
     */
    private function runAggregate(array $pipeline, string $purpose): array
    {
        EventBus::dispatch('belluga.events.management_occurrence_aggregate', [
            $purpose,
            $pipeline,
        ]);

        return EventOccurrence::raw(
            fn ($collection) => $collection->aggregate($pipeline)
        )->all();
    }

    /**
     * @return array<int, string|ObjectId>
     */
    private function buildProfileIdCandidates(string $profileId): array
    {
        $candidates = [$profileId];

        if ($this->looksLikeObjectId($profileId)) {
            $candidates[] = new ObjectId($profileId);
        }

        return $candidates;
    }

    private function looksLikeObjectId(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{24}$/i', $value);
    }
}
