<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityResolverContract;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

final class AccountProfileTypeChangeImpactService
{
    private const SAMPLE_LIMIT = 20;

    public function __construct(
        private readonly AccountProfileCapabilityResolverContract $resolver,
        private readonly AccountProfileLocationPolicy $locationPolicy,
        private readonly PhysicalHostEligibilityPolicy $physicalHostEligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $capabilityPatch
     * @return array{profile_type:string,capability_revision:int,missing_location_count:int,map_projection_count:int,event_reference_count:int,sample_profile_ids:array<int, string>}
     */
    public function preview(
        string $profileType,
        array $capabilityPatch,
        ?AccountProfileTransactionContext $context = null,
    ): array {
        $type = TenantProfileType::query()->where('type', trim($profileType))->first();
        if (! $type instanceof TenantProfileType) {
            abort(404, 'Profile type not found.');
        }

        $current = $this->arrayFrom($type->capabilities ?? []);
        $next = $this->resolver->mergeConfigurationForUpdate($capabilityPatch, $current);
        $currentPolicy = (string) $this->resolver->resolveForProfileType($type, 'location_policy')['effective']['value'];
        $candidate = $type->replicate();
        $candidate->capabilities = $next;
        $nextPolicy = (string) $this->resolver->resolveForProfileType($candidate, 'location_policy')['effective']['value'];
        $currentMap = $this->resolver->resolveForProfileType($type, 'is_map_poi_enabled')['effective']['value'] === true;
        $nextMap = $this->resolver->resolveForProfileType($candidate, 'is_map_poi_enabled')['effective']['value'] === true;
        $currentHost = $this->resolver->resolveForProfileType($type, 'is_physical_host_enabled')['effective']['value'] === true;
        $nextHost = $this->resolver->resolveForProfileType($candidate, 'is_physical_host_enabled')['effective']['value'] === true;

        $missingLocationCount = $nextPolicy === 'required' && $currentPolicy !== 'required'
            ? $this->countProfilesWithoutValidLocation((string) $type->type, $context)
            : 0;
        $mapProjectionCount = $currentMap !== $nextMap
            ? $this->countMapProjectionDifferences((string) $type->type, $nextMap, $context)
            : 0;
        $eventReferenceCount = $currentHost && ! $nextHost
            ? $this->countEventReferences((string) $type->type, $context)
            : 0;

        $sampleIds = $this->sampleAffectedProfileIds(
            (string) $type->type,
            includeMissingLocation: $missingLocationCount > 0,
            includeMapDifferences: $mapProjectionCount > 0,
            nextMapEnabled: $nextMap,
            includeEventReferences: $eventReferenceCount > 0,
            context: $context,
        );

        return [
            'profile_type' => (string) $type->type,
            'capability_revision' => max(0, (int) ($type->capability_revision ?? 0)),
            'missing_location_count' => $missingLocationCount,
            'map_projection_count' => $mapProjectionCount,
            'event_reference_count' => $eventReferenceCount,
            'sample_profile_ids' => $sampleIds,
        ];
    }

    /** @param array<string, mixed> $capabilityPatch */
    public function assertMutationAllowed(
        string $profileType,
        array $capabilityPatch,
        ?AccountProfileTransactionContext $context = null,
    ): array {
        $impact = $this->preview($profileType, $capabilityPatch, $context);
        if ($impact['missing_location_count'] > 0) {
            throw ValidationException::withMessages([
                'capabilities.location_policy.value' => [
                    "{$impact['missing_location_count']} account profile(s) do not have a valid location.",
                ],
            ]);
        }

        if ($impact['event_reference_count'] > 0) {
            $locationPolicy = $this->arrayFrom($capabilityPatch['location_policy'] ?? [])['value'] ?? null;
            $field = $locationPolicy === 'disabled'
                ? 'capabilities.location_policy.value'
                : 'capabilities.is_physical_host_enabled.value';
            throw new HttpResponseException(response()->json([
                'message' => 'The location capability is used by an event.',
                'code' => 'account_profile_location_in_use',
                'errors' => [
                    $field => [
                        "{$impact['event_reference_count']} event reference(s) would become invalid.",
                    ],
                ],
                'data' => $impact,
            ], 409));
        }

        return $impact;
    }

    public function assertProfileMutationAllowed(
        AccountProfile $profile,
        TenantProfileType $nextProfileType,
        mixed $nextLocation,
        ?AccountProfileTransactionContext $context = null,
    ): void {
        if ($this->physicalHostEligibility->isEligibleForTypeAndLocation($nextProfileType, $nextLocation)) {
            return;
        }

        $profileId = trim((string) $profile->getKey());
        $referenceCount = $profileId === '' ? 0 : $this->countEventReferencesForProfile($profileId, $context);
        if ($referenceCount === 0) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'The account profile location is used by an event.',
            'code' => 'account_profile_location_in_use',
            'errors' => [
                'location' => ["{$referenceCount} event reference(s) would become invalid."],
            ],
        ], 409));
    }

    private function countProfilesWithoutValidLocation(
        string $profileType,
        ?AccountProfileTransactionContext $context,
    ): int {
        return $this->profiles($context)->countDocuments([
            'profile_type' => $profileType,
            '$nor' => [$this->locationPolicy->validPointMatchExpression()],
        ], $this->options($context));
    }

    private function countMapProjectionDifferences(
        string $profileType,
        bool $nextMapEnabled,
        ?AccountProfileTransactionContext $context,
    ): int {
        $pipeline = [
            ...$this->mapProjectionDifferenceStages($profileType, $nextMapEnabled),
            ['$count' => 'total'],
        ];
        $row = $this->profiles($context)->aggregate($pipeline, $this->options($context))->toArray()[0] ?? null;

        return is_array($row) || $row instanceof BSONDocument
            ? (int) ($row['total'] ?? 0)
            : 0;
    }

    /** @return list<array<string, mixed>> */
    private function mapProjectionDifferenceStages(string $profileType, bool $nextMapEnabled): array
    {
        return [
            ['$match' => ['profile_type' => $profileType]],
            ['$set' => [
                '__impact_profile_id' => ['$toString' => '$_id'],
                '__impact_profile_ids' => ['$_id', ['$toString' => '$_id']],
                '__impact_coordinates' => ['$cond' => [
                    ['$isArray' => '$location.coordinates'],
                    '$location.coordinates',
                    [],
                ]],
            ]],
            ['$set' => [
                '__impact_lng' => ['$convert' => [
                    'input' => ['$arrayElemAt' => ['$__impact_coordinates', 0]],
                    'to' => 'double',
                    'onError' => null,
                    'onNull' => null,
                ]],
                '__impact_lat' => ['$convert' => [
                    'input' => ['$arrayElemAt' => ['$__impact_coordinates', 1]],
                    'to' => 'double',
                    'onError' => null,
                    'onNull' => null,
                ]],
            ]],
            ['$lookup' => [
                'from' => 'map_pois',
                'localField' => '__impact_profile_ids',
                'foreignField' => 'ref_id',
                'pipeline' => [
                    ['$match' => ['ref_type' => 'account_profile']],
                    ['$limit' => 1],
                ],
                'as' => '__impact_map_rows',
            ]],
            ['$set' => [
                '__impact_desired' => ['$and' => [
                    $nextMapEnabled,
                    ['$eq' => [['$ifNull' => ['$deleted_at', null]], null]],
                    ['$eq' => ['$location.type', 'Point']],
                    ['$eq' => [['$size' => '$__impact_coordinates'], 2]],
                    ['$ne' => ['$__impact_lng', null]],
                    ['$ne' => ['$__impact_lat', null]],
                    ['$gte' => ['$__impact_lng', -180]],
                    ['$lte' => ['$__impact_lng', 180]],
                    ['$gte' => ['$__impact_lat', -90]],
                    ['$lte' => ['$__impact_lat', 90]],
                ]],
                '__impact_current' => ['$gt' => [['$size' => '$__impact_map_rows'], 0]],
            ]],
            ['$match' => ['$expr' => ['$ne' => ['$__impact_desired', '$__impact_current']]]],
        ];
    }

    private function countEventReferences(
        string $profileType,
        ?AccountProfileTransactionContext $context,
    ): int {
        return $this->countReferencesInCollection('events', $profileType, false, $context)
            + $this->countReferencesInCollection('event_occurrences', $profileType, false, $context)
            + $this->countReferencesInCollection('event_occurrences', $profileType, true, $context);
    }

    private function countEventReferencesForProfile(
        string $profileId,
        ?AccountProfileTransactionContext $context,
    ): int {
        $database = $context?->database() ?? DB::connection('tenant')->getDatabase();
        $options = $this->options($context);
        $profileIdCandidates = $this->profileIdCandidates($profileId);

        return $database->selectCollection('events')->countDocuments(
            $this->placeReferenceFilter('place_ref', $profileIdCandidates),
            $options,
        ) + $database->selectCollection('event_occurrences')->countDocuments(
            $this->placeReferenceFilter('place_ref', $profileIdCandidates),
            $options,
        ) + $database->selectCollection('event_occurrences')->countDocuments([
            'programming_items' => ['$elemMatch' => [
                'place_ref.type' => 'account_profile',
                '$or' => [
                    ['place_ref.id' => ['$in' => $profileIdCandidates]],
                    ['place_ref._id' => ['$in' => $profileIdCandidates]],
                ],
            ]],
        ], $options);
    }

    private function countReferencesInCollection(
        string $collection,
        string $profileType,
        bool $programmingItems,
        ?AccountProfileTransactionContext $context,
    ): int {
        $pipeline = [];
        if ($programmingItems) {
            $pipeline[] = ['$match' => ['programming_items.place_ref.type' => 'account_profile']];
            $pipeline[] = ['$unwind' => '$programming_items'];
            $pipeline[] = ['$match' => ['programming_items.place_ref.type' => 'account_profile']];
            $referenceId = ['$ifNull' => [
                '$programming_items.place_ref.id',
                '$programming_items.place_ref._id',
            ]];
        } else {
            $pipeline[] = ['$match' => ['place_ref.type' => 'account_profile']];
            $referenceId = ['$ifNull' => ['$place_ref.id', '$place_ref._id']];
        }

        $pipeline[] = ['$lookup' => [
            'from' => 'account_profiles',
            'let' => ['profile_id' => $referenceId],
            'pipeline' => [
                ['$match' => ['$expr' => ['$eq' => [
                    '$_id',
                    ['$convert' => [
                        'input' => '$$profile_id',
                        'to' => 'objectId',
                        'onError' => null,
                        'onNull' => null,
                    ]],
                ]]]],
                ['$match' => ['profile_type' => $profileType]],
                ['$limit' => 1],
            ],
            'as' => 'matched_profile',
        ]];
        $pipeline[] = ['$match' => ['matched_profile.0' => ['$exists' => true]]];
        $pipeline[] = ['$group' => ['_id' => '$_id']];
        $pipeline[] = ['$count' => 'total'];

        $row = ($context?->database() ?? DB::connection('tenant')->getDatabase())
            ->selectCollection($collection)
            ->aggregate($pipeline, $this->options($context))
            ->toArray()[0] ?? null;

        return is_array($row) || $row instanceof BSONDocument
            ? (int) ($row['total'] ?? 0)
            : 0;
    }

    /** @return array<int, string|ObjectId> */
    private function profileIdCandidates(string $profileId): array
    {
        $candidates = [$profileId];

        try {
            $candidates[] = new ObjectId($profileId);
        } catch (\Throwable) {
            // Account-profile ids are ObjectIds, but the string remains valid for legacy references.
        }

        return $candidates;
    }

    /**
     * @param  array<int, string|ObjectId>  $profileIdCandidates
     * @return array<string, mixed>
     */
    private function placeReferenceFilter(string $field, array $profileIdCandidates): array
    {
        return [
            "{$field}.type" => 'account_profile',
            '$or' => [
                ["{$field}.id" => ['$in' => $profileIdCandidates]],
                ["{$field}._id" => ['$in' => $profileIdCandidates]],
            ],
        ];
    }

    /** @return list<string> */
    private function sampleAffectedProfileIds(
        string $profileType,
        bool $includeMissingLocation,
        bool $includeMapDifferences,
        bool $nextMapEnabled,
        bool $includeEventReferences,
        ?AccountProfileTransactionContext $context,
    ): array {
        if (! $includeMissingLocation && ! $includeMapDifferences && ! $includeEventReferences) {
            return [];
        }

        $pipeline = [
            ['$match' => ['profile_type' => $profileType]],
            ['$set' => [
                '__impact_profile_id' => ['$toString' => '$_id'],
                '__impact_profile_ids' => ['$_id', ['$toString' => '$_id']],
                '__impact_coordinates' => ['$cond' => [
                    ['$isArray' => '$location.coordinates'],
                    '$location.coordinates',
                    [],
                ]],
            ]],
            ['$set' => [
                '__impact_lng' => ['$convert' => [
                    'input' => ['$arrayElemAt' => ['$__impact_coordinates', 0]],
                    'to' => 'double',
                    'onError' => null,
                    'onNull' => null,
                ]],
                '__impact_lat' => ['$convert' => [
                    'input' => ['$arrayElemAt' => ['$__impact_coordinates', 1]],
                    'to' => 'double',
                    'onError' => null,
                    'onNull' => null,
                ]],
            ]],
        ];
        if ($includeMapDifferences) {
            $pipeline[] = ['$lookup' => [
                'from' => 'map_pois',
                'localField' => '__impact_profile_ids',
                'foreignField' => 'ref_id',
                'pipeline' => [
                    ['$match' => ['ref_type' => 'account_profile']],
                    ['$limit' => 1],
                ],
                'as' => '__impact_map_rows',
            ]];
        }
        if ($includeEventReferences) {
            array_push($pipeline, ...$this->referenceLookupStages('events', 'place_ref', '__impact_event_refs'));
            array_push($pipeline, ...$this->referenceLookupStages(
                'event_occurrences',
                'place_ref',
                '__impact_occurrence_refs',
            ));
            array_push($pipeline, ...$this->programmingReferenceLookupStages());
        }

        $validLocation = ['$and' => [
            ['$eq' => ['$location.type', 'Point']],
            ['$eq' => [['$size' => '$__impact_coordinates'], 2]],
            ['$ne' => ['$__impact_lng', null]],
            ['$ne' => ['$__impact_lat', null]],
            ['$gte' => ['$__impact_lng', -180]],
            ['$lte' => ['$__impact_lng', 180]],
            ['$gte' => ['$__impact_lat', -90]],
            ['$lte' => ['$__impact_lat', 90]],
        ]];
        $affected = [];
        if ($includeMissingLocation) {
            $affected[] = ['$not' => [$validLocation]];
        }
        if ($includeMapDifferences) {
            $affected[] = ['$ne' => [
                ['$and' => [
                    $nextMapEnabled,
                    ['$eq' => [['$ifNull' => ['$deleted_at', null]], null]],
                    $validLocation,
                ]],
                ['$gt' => [['$size' => '$__impact_map_rows'], 0]],
            ]];
        }
        if ($includeEventReferences) {
            $affected[] = ['$or' => [
                ['$gt' => [['$size' => '$__impact_event_refs_string'], 0]],
                ['$gt' => [['$size' => '$__impact_event_refs_object'], 0]],
                ['$gt' => [['$size' => '$__impact_occurrence_refs_string'], 0]],
                ['$gt' => [['$size' => '$__impact_occurrence_refs_object'], 0]],
                ['$gt' => [['$size' => '$__impact_programming_string_refs'], 0]],
                ['$gt' => [['$size' => '$__impact_programming_object_refs'], 0]],
            ]];
        }
        $pipeline[] = ['$match' => ['$expr' => count($affected) === 1 ? $affected[0] : ['$or' => $affected]]];
        $pipeline[] = ['$project' => ['_id' => '$__impact_profile_id']];
        $pipeline[] = ['$sort' => ['_id' => 1]];
        $pipeline[] = ['$limit' => self::SAMPLE_LIMIT];

        $ids = [];
        foreach ($this->profiles($context)->aggregate($pipeline, $this->options($context)) as $row) {
            $id = trim((string) ($row['_id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> */
    private function referenceLookupStages(string $collection, string $path, string $as): array
    {
        return [
            ['$lookup' => [
                'from' => $collection,
                'localField' => '__impact_profile_ids',
                'foreignField' => "{$path}.id",
                'pipeline' => [
                    ['$match' => ["{$path}.type" => 'account_profile']],
                    ['$project' => ['_id' => 1]],
                    ['$limit' => 1],
                ],
                'as' => "{$as}_string",
            ]],
            ['$lookup' => [
                'from' => $collection,
                'localField' => '__impact_profile_ids',
                'foreignField' => "{$path}._id",
                'pipeline' => [
                    ['$match' => ["{$path}.type" => 'account_profile']],
                    ['$project' => ['_id' => 1]],
                    ['$limit' => 1],
                ],
                'as' => "{$as}_object",
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function programmingReferenceLookupStages(): array
    {
        return [
            ['$lookup' => [
                'from' => 'event_occurrences',
                'localField' => '__impact_profile_ids',
                'foreignField' => 'programming_items.place_ref.id',
                'pipeline' => [
                    ['$match' => ['programming_items.place_ref.type' => 'account_profile']],
                    ['$project' => ['_id' => 1]],
                    ['$limit' => 1],
                ],
                'as' => '__impact_programming_string_refs',
            ]],
            ['$lookup' => [
                'from' => 'event_occurrences',
                'localField' => '__impact_profile_ids',
                'foreignField' => 'programming_items.place_ref._id',
                'pipeline' => [
                    ['$match' => ['programming_items.place_ref.type' => 'account_profile']],
                    ['$project' => ['_id' => 1]],
                    ['$limit' => 1],
                ],
                'as' => '__impact_programming_object_refs',
            ]],
        ];
    }

    private function profiles(?AccountProfileTransactionContext $context): \MongoDB\Collection
    {
        return ($context?->database() ?? DB::connection('tenant')->getDatabase())
            ->selectCollection('account_profiles');
    }

    /** @return array<string, mixed> */
    private function options(?AccountProfileTransactionContext $context): array
    {
        return $context?->rawOptions() ?? [];
    }

    /** @return array<string, mixed> */
    private function arrayFrom(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            return $value->getArrayCopy();
        }
        if ($value instanceof \Traversable) {
            return iterator_to_array($value);
        }

        return [];
    }
}
