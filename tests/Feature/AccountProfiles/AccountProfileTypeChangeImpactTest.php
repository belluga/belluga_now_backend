<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileLocationPolicy;
use App\Application\AccountProfiles\AccountProfileTypeChangeImpactService;
use App\Application\AccountProfiles\Capabilities\AccountProfileCapabilityRegistry;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\ObjectId;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Tests\Helpers\TenantLabels;
use Tests\Support\MongoCommandTrace;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

final class AccountProfileTypeChangeImpactTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            app(SystemInitializationService::class)->initialize(new InitializationPayload(
                landlord: ['name' => 'Landlord HQ'],
                tenant: ['name' => 'Change Impact', 'subdomain' => 'change-impact'],
                role: ['name' => 'Root', 'permissions' => ['*']],
                user: ['name' => 'Root User', 'email' => 'change-impact@example.org', 'password' => 'Secret!234'],
                themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                logoSettings: ['light_logo_uri' => '/logos/light.png'],
                pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
                tenantDomains: ['change-impact.test'],
            ));
            self::$bootstrapped = true;
        }
        Tenant::query()->firstOrFail()->makeCurrent();
        foreach (['account_profile_types', 'account_profiles', 'events', 'event_occurrences'] as $collection) {
            DB::connection('tenant')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
    }

    public function test_preview_and_mutation_share_required_location_impact(): void
    {
        $this->type();
        $located = AccountProfile::create([
            'account_id' => 'impact-account-located',
            'profile_type' => 'place',
            'display_name' => 'Located',
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
        ]);
        $missing = AccountProfile::create([
            'account_id' => 'impact-account-missing',
            'profile_type' => 'place',
            'display_name' => 'Missing',
            'location' => null,
        ]);
        $patch = ['location_policy' => ['value' => 'required', 'parameters' => []]];
        $service = app(AccountProfileTypeChangeImpactService::class);

        $impact = $service->preview('place', $patch);
        self::assertSame(4, $impact['capability_revision']);
        self::assertSame(1, $impact['missing_location_count']);
        self::assertSame(0, $impact['map_projection_count']);
        self::assertSame([(string) $missing->getKey()], $impact['sample_profile_ids']);

        try {
            $service->assertMutationAllowed('place', $patch);
            self::fail('Expected the shared mutation guard to reject the same impact.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('capabilities.location_policy.value', $exception->errors());
        }
    }

    public function test_disabling_location_makes_declared_dependents_dormant_and_reports_projection_and_event_impact(): void
    {
        $this->type();
        $profile = AccountProfile::create([
            'account_id' => 'impact-account-host',
            'profile_type' => 'place',
            'display_name' => 'Host',
            'location' => ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
        ]);
        AccountProfile::create([
            'account_id' => 'impact-account-unaffected',
            'profile_type' => 'place',
            'display_name' => 'Unaffected',
            'location' => ['type' => 'Point', 'coordinates' => [-43.1, -22.8]],
        ]);
        DB::connection('tenant')->getDatabase()->selectCollection('events')->insertOne([
            'place_ref' => ['type' => 'account_profile', '_id' => (string) $profile->getKey()],
        ]);
        $service = app(AccountProfileTypeChangeImpactService::class);

        $dormant = [
            'location_policy' => ['value' => 'disabled', 'parameters' => []],
        ];
        $impact = $service->preview('place', $dormant);
        self::assertSame(0, $impact['map_projection_count']);
        self::assertSame(1, $impact['event_reference_count']);
        self::assertSame([(string) $profile->getKey()], $impact['sample_profile_ids']);

        try {
            $service->assertMutationAllowed('place', $dormant);
            self::fail('Expected referenced host removal to fail closed.');
        } catch (HttpResponseException $exception) {
            self::assertSame(409, $exception->getResponse()->getStatusCode());
            self::assertStringContainsString(
                'account_profile_location_in_use',
                (string) $exception->getResponse()->getContent(),
            );
        }
    }

    public function test_impact_queries_use_bounded_samples_and_explainable_indexes(): void
    {
        $this->type();
        $database = DB::connection('tenant')->getDatabase();
        $profiles = [];
        foreach (range(1, 25) as $index) {
            $profiles[] = [
                '_id' => new ObjectId,
                'account_id' => 'impact-account-'.$index,
                'profile_type' => 'place',
                'display_name' => 'Impact '.$index,
                'slug' => 'impact-'.$index,
                'location' => $index <= 21
                    ? null
                    : ['type' => 'Point', 'coordinates' => [-43.2, -22.9]],
            ];
        }
        $database->selectCollection('account_profiles')->insertMany($profiles);
        $locatedIds = array_map(
            static fn (array $profile): string => (string) $profile['_id'],
            array_slice($profiles, 21),
        );
        $database->selectCollection('events')->insertOne([
            'place_ref' => ['type' => 'account_profile', '_id' => new ObjectId($locatedIds[0])],
        ]);
        $database->selectCollection('event_occurrences')->insertMany([
            [
                'event_id' => 'impact-event-direct',
                'occurrence_index' => 0,
                'occurrence_slug' => 'impact-direct',
                'place_ref' => ['type' => 'account_profile', 'id' => $locatedIds[1]],
            ],
            [
                'event_id' => 'impact-event-programming',
                'occurrence_index' => 0,
                'occurrence_slug' => 'impact-programming',
                'programming_items' => [[
                    'place_ref' => ['type' => 'account_profile', '_id' => new ObjectId($locatedIds[2])],
                ]],
            ],
        ]);

        $client = DB::connection('tenant')->getMongoClient();
        $trace = new MongoCommandTrace;
        $client->addSubscriber($trace);
        try {
            $requiredImpact = app(AccountProfileTypeChangeImpactService::class)->preview('place', [
                'location_policy' => ['value' => 'required', 'parameters' => []],
            ]);
            $disableImpact = app(AccountProfileTypeChangeImpactService::class)->preview('place', [
                'location_policy' => ['value' => 'disabled', 'parameters' => []],
            ]);
        } finally {
            $client->removeSubscriber($trace);
        }

        self::assertSame(21, $requiredImpact['missing_location_count']);
        self::assertCount(20, $requiredImpact['sample_profile_ids']);
        self::assertSame(0, $disableImpact['map_projection_count']);
        self::assertSame(3, $disableImpact['event_reference_count']);
        self::assertSame([], $this->valuesForKey($trace->commands(), '$in'));
        self::assertContains(20, $this->valuesForKey($trace->commands(), '$limit'));

        $samplePipeline = collect($trace->aggregatePipelinesForCollection('account_profiles'))
            ->first(static fn (array $pipeline): bool => str_contains(
                json_encode($pipeline, JSON_THROW_ON_ERROR),
                '__impact_programming_object_refs',
            ));
        self::assertIsArray($samplePipeline);
        $sampleExplain = $this->native($database->command([
            'explain' => [
                'aggregate' => 'account_profiles',
                'pipeline' => $samplePipeline,
                'cursor' => new \stdClass,
            ],
            'verbosity' => 'executionStats',
        ])->toArray()[0]);
        $lookupStages = array_values(array_filter(
            $sampleExplain['stages'] ?? [],
            static fn (array $stage): bool => array_key_exists('$lookup', $stage),
        ));
        self::assertCount(6, $lookupStages);
        foreach ($lookupStages as $stage) {
            self::assertSame(0, (int) ($stage['collectionScans'] ?? -1));
            self::assertNotEmpty($stage['indexesUsed'] ?? []);
        }

        $this->assertFindExplainUsesIndex(
            'account_profiles',
            [
                'profile_type' => 'place',
                '$nor' => [app(AccountProfileLocationPolicy::class)->validPointMatchExpression()],
            ],
            'idx_account_profiles_profile_type_location_type_v1',
            25,
        );
        $this->assertFindExplainUsesIndex(
            'account_profiles',
            [
                'profile_type' => 'place',
                'location.type' => 'Point',
                'location.coordinates.0' => ['$type' => 'number'],
                'location.coordinates.1' => ['$type' => 'number'],
            ],
            'idx_account_profiles_profile_type_location_type_v1',
            25,
        );
        $this->assertFindExplainUsesIndex(
            'events',
            ['place_ref.type' => 'account_profile'],
            'idx_events_place_ref_type_id_v1',
            1,
        );
        $this->assertFindExplainUsesIndex(
            'events',
            [
                'place_ref.type' => 'account_profile',
                'place_ref._id' => new ObjectId($locatedIds[0]),
            ],
            'idx_events_place_ref_type_native_id_v1',
            1,
        );
        $this->assertFindExplainUsesIndex(
            'event_occurrences',
            ['place_ref.type' => 'account_profile'],
            'idx_event_occurrences_place_ref_type_id_v1',
            2,
        );
        $this->assertFindExplainUsesIndex(
            'event_occurrences',
            [
                'place_ref.type' => 'account_profile',
                'place_ref._id' => new ObjectId($locatedIds[1]),
            ],
            'idx_event_occurrences_place_ref_type_native_id_v1',
            2,
        );
        $this->assertFindExplainUsesIndex(
            'event_occurrences',
            ['programming_items.place_ref.type' => 'account_profile'],
            'idx_event_occurrences_programming_place_ref_type_id_v1',
            2,
        );
        $this->assertFindExplainUsesIndex(
            'event_occurrences',
            [
                'programming_items.place_ref.type' => 'account_profile',
                'programming_items.place_ref._id' => new ObjectId($locatedIds[2]),
            ],
            'idx_event_occurrences_programming_place_ref_type_native_id_v1',
            2,
        );
    }

    private function type(): void
    {
        $registry = app(AccountProfileCapabilityRegistry::class);
        TenantProfileType::create([
            'type' => 'place',
            'capabilities' => $registry->completeCreationConfiguration([
                'location_policy' => ['value' => 'optional', 'parameters' => []],
                'is_map_poi_enabled' => ['value' => true, 'parameters' => []],
                'is_physical_host_enabled' => ['value' => true, 'parameters' => []],
                'is_reference_location_enabled' => ['value' => true, 'parameters' => []],
            ]),
            'capability_revision' => 4,
            'host_admission_fence_revision' => 2,
        ]);
    }

    /** @param array<string, mixed> $filter */
    private function assertFindExplainUsesIndex(
        string $collection,
        array $filter,
        string $index,
        int $maximumDocumentsExamined,
    ): void {
        $explain = $this->native(DB::connection('tenant')->getDatabase()->command([
            'explain' => [
                'find' => $collection,
                'filter' => $filter,
                'hint' => $index,
            ],
            'verbosity' => 'executionStats',
        ])->toArray()[0]);

        self::assertContains($index, $this->valuesForKey($explain, 'indexName'));
        self::assertLessThanOrEqual(
            $maximumDocumentsExamined,
            (int) ($explain['executionStats']['totalDocsExamined'] ?? PHP_INT_MAX),
        );
    }

    private function native(mixed $value): mixed
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->native($item);
            }
        }

        return $value;
    }

    /** @return list<mixed> */
    private function valuesForKey(mixed $value, string $expectedKey): array
    {
        if (! is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $key => $item) {
            if ($key === $expectedKey) {
                $values[] = $item;
            }
            array_push($values, ...$this->valuesForKey($item, $expectedKey));
        }

        return $values;
    }
}
