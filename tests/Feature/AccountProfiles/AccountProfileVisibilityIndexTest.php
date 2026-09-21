<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileNestedGroupMemberStore;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Integration\Events\AccountProfileResolverAdapter;
use App\Integration\Favorites\AccountProfileFavoriteDirectReadService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\SeedsTenantAccounts;

final class AccountProfileVisibilityIndexTest extends TestCaseTenant
{
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get => $this->landlord->tenant_primary;
    }

    private Account $account;

    private AccountProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail()->makeCurrent();
        FavoriteEdge::query()->delete();
        AccountProfile::query()->withTrashed()->forceDelete();
        TenantProfileType::query()->delete();
        DB::connection('tenant')->getDatabase()
            ->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)
            ->deleteMany([]);

        [$this->account] = $this->seedAccountWithRole(['account-users:view']);
        $this->createVisibleType();
        $this->profile = $this->createPublicProfile(
            account: $this->account,
            displayName: 'Visibility Index Profile',
            slug: 'visibility-index-profile',
            location: ['type' => 'Point', 'coordinates' => [-43.1729, -22.9068]],
        );
    }

    public function test_public_catalog_uses_an_indexed_published_parent_aggregate_before_page_slicing(): void
    {
        [$payload, $trace] = $this->capture(function (): array {
            return app(AccountProfileQueryService::class)->publicPageEnvelope([], 10);
        });

        self::assertContains('visibility-index-profile', array_column($payload['data'], 'slug'));
        $command = $trace->first('aggregate', 'account_profiles');
        self::assertNotNull($command);
        $this->assertPublishedParentGatePrecedesPageSlice(
            $this->normalizeBson($command['pipeline']),
        );
        $this->assertHealthyExplain($this->explain($command), allowGeoNear: false);
    }

    public function test_public_near_uses_an_indexed_published_parent_aggregate_before_page_slicing(): void
    {
        [$payload, $trace] = $this->capture(function (): array {
            return app(AccountProfileQueryService::class)->publicNear([
                'origin_lat' => -22.9068,
                'origin_lng' => -43.1729,
                'page_size' => 10,
            ]);
        });

        self::assertContains('visibility-index-profile', array_column($payload['data'], 'slug'));
        $command = $trace->first('aggregate', 'account_profiles');
        self::assertNotNull($command);
        $pipeline = $this->normalizeBson($command['pipeline']);
        self::assertArrayHasKey('$geoNear', $pipeline[0]);
        $this->assertPublishedParentGatePrecedesPageSlice($pipeline);
        $this->assertHealthyExplain($this->explain($command), allowGeoNear: true);
    }

    public function test_public_nested_members_use_an_indexed_published_parent_aggregate_before_page_slicing(): void
    {
        [$parentAccount] = $this->seedAccountWithRole(['account-users:view']);
        $parent = $this->createPublicProfile(
            account: $parentAccount,
            displayName: 'Visibility Index Parent',
            slug: 'visibility-index-parent',
        );
        $groupId = 'visibility-members';
        $parentId = (string) $parent->getKey();
        $memberId = (string) $this->profile->getKey();
        $tenantId = (string) $this->tenant->id;
        $collection = DB::connection('tenant')->getDatabase()
            ->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION);
        $collection->insertMany([
            [
                '_id' => "accounts-nested:head:account_profile:{$parentId}:{$groupId}",
                'tenant_id' => $tenantId,
                'parent_type' => 'account_profile',
                'parent_id' => $parentId,
                'group_key' => $groupId,
                'group_label' => 'Visibility members',
                'group_order' => 0,
                'doc_type' => 'group_head',
            ],
            [
                '_id' => "accounts-nested:member:account_profile:{$parentId}:{$groupId}:{$memberId}",
                'tenant_id' => $tenantId,
                'parent_type' => 'account_profile',
                'parent_id' => $parentId,
                'group_key' => $groupId,
                'doc_type' => 'member_row',
                'item_order' => 0,
                'nested_profile' => ['id' => $memberId],
            ],
        ]);

        [$payload, $trace] = $this->capture(function () use ($parent, $groupId): array {
            return app(AccountProfileNestedGroupMemberStore::class)->publicMemberPage(
                $parent,
                $groupId,
                10,
                null,
                null,
                null,
            );
        });

        self::assertSame($memberId, $payload['data'][0]['id']);
        $command = $trace->first('aggregate', AccountProfileNestedGroupMemberStore::COLLECTION);
        self::assertNotNull($command);
        $this->assertPublishedParentGatePrecedesPageSlice(
            $this->normalizeBson($command['pipeline']),
        );
        $this->assertHealthyExplain($this->explain($command), allowGeoNear: false);
    }

    public function test_public_event_related_profile_and_parent_reads_are_indexed(): void
    {
        [$payload, $trace] = $this->capture(function (): array {
            return app(AccountProfileResolverAdapter::class)
                ->resolveExistingPublicEventPartyProfilesByIds([(string) $this->profile->getKey()]);
        });

        self::assertArrayHasKey((string) $this->profile->getKey(), $payload);
        $this->assertHealthyExplain(
            $this->explain($trace->first('find', 'account_profiles')),
            allowGeoNear: false,
        );
        $this->assertHealthyExplain(
            $this->explain($trace->first('find', 'accounts')),
            allowGeoNear: false,
        );
    }

    public function test_favorite_profile_and_published_account_reads_are_indexed_without_claiming_ranking_pagination(): void
    {
        FavoriteEdge::query()->create([
            'owner_user_id' => 'visibility-index-owner',
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => (string) $this->profile->getKey(),
        ]);

        [$payload, $trace] = $this->capture(function (): array {
            return app(AccountProfileFavoriteDirectReadService::class)
                ->listForOwner('visibility-index-owner', 1, 10);
        });

        self::assertSame((string) $this->profile->getKey(), $payload['items'][0]['target_id']);
        $this->assertHealthyExplain(
            $this->explain($trace->first('find', 'account_profiles')),
            allowGeoNear: false,
        );
        $this->assertHealthyExplain(
            $this->explain($trace->first('find', 'accounts')),
            allowGeoNear: false,
        );
    }

    public function test_public_direct_detail_profile_and_parent_reads_are_bounded_and_indexed(): void
    {
        [$profile, $trace] = $this->capture(function (): AccountProfile {
            return app(AccountProfileQueryService::class)
                ->publicFindBySlugOrFail('visibility-index-profile');
        });

        self::assertSame('visibility-index-profile', $profile->slug);
        self::assertSame(1, $trace->count('find', 'accounts'));
        $this->assertHealthyExplain(
            $this->explain($trace->first('find', 'account_profiles')),
            allowGeoNear: false,
        );
        $this->assertHealthyExplain(
            $this->explain($trace->first('find', 'accounts')),
            allowGeoNear: false,
        );
    }

    private function createVisibleType(): void
    {
        TenantProfileType::query()->create([
            'type' => 'visibility-index',
            'label' => 'Visibility Index',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => ['value' => true, 'parameters' => []],
                'is_publicly_discoverable' => ['value' => true, 'parameters' => []],
                'is_publicly_navigable' => ['value' => true, 'parameters' => []],
                'is_favoritable' => ['value' => true, 'parameters' => []],
                'location_policy' => ['value' => 'optional', 'parameters' => []],
                'has_nested_profile_groups' => ['value' => true, 'parameters' => []],
            ],
        ]);
    }

    private function createPublicProfile(
        Account $account,
        string $displayName,
        string $slug,
        ?array $location = null,
    ): AccountProfile {
        return AccountProfile::query()->create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => 'visibility-index',
            'display_name' => $displayName,
            'slug' => $slug,
            'visibility' => 'public',
            'is_active' => true,
            'location' => $location,
        ]);
    }

    /** @return array{0:mixed,1:AccountProfileVisibilityBsonCommandTrace} */
    private function capture(callable $operation): array
    {
        $trace = new AccountProfileVisibilityBsonCommandTrace;
        $client = DB::connection('tenant')->getMongoClient();
        $client->addSubscriber($trace);
        try {
            return [$operation(), $trace];
        } finally {
            $client->removeSubscriber($trace);
        }
    }

    /** @param array<string,mixed>|null $command @return array<string,mixed> */
    private function explain(?array $command): array
    {
        self::assertIsArray($command, 'The exercised read must issue its expected MongoDB command.');
        $name = isset($command['aggregate']) ? 'aggregate' : 'find';
        $fields = $name === 'aggregate'
            ? ['aggregate', 'pipeline', 'cursor', 'allowDiskUse', 'collation', 'hint', 'let']
            : ['find', 'filter', 'projection', 'sort', 'skip', 'limit', 'collation', 'hint', 'let'];
        $actual = array_intersect_key($command, array_flip($fields));

        self::assertArrayHasKey($name, $actual);

        return $this->normalizeBson(DB::connection('tenant')->getDatabase()->command([
            'explain' => $actual,
            'verbosity' => 'executionStats',
        ])->toArray()[0]);
    }

    /** @param array<string,mixed> $explain */
    private function assertHealthyExplain(array $explain, bool $allowGeoNear): void
    {
        $evaluatedPlans = $this->evaluatedPlans($explain);
        $stages = [];
        $indexNames = [];
        $examined = [];
        $returned = [];
        foreach ($evaluatedPlans as $plan) {
            $stages = [...$stages, ...$this->valuesForKey($plan, 'stage')];
            $indexNames = [...$indexNames, ...$this->valuesForKey($plan, 'indexName')];
            $examined = [...$examined, ...$this->valuesForKey($plan, 'totalDocsExamined')];
            $returned = [...$returned, ...$this->valuesForKey($plan, 'nReturned')];
        }
        if ($allowGeoNear) {
            self::assertContains('GEO_NEAR_2DSPHERE', $stages);
        } else {
            self::assertNotEmpty($indexNames);
        }
        self::assertNotContains('COLLSCAN', $stages);
        self::assertNotContains(true, $this->valuesForKey($explain['stages'] ?? [], 'usedDisk'));
        self::assertNotEmpty($examined, 'The executed explain must report documents examined.');
        self::assertNotEmpty($returned, 'The executed explain must report returned fixture rows.');
        self::assertGreaterThan(0, max(array_map('intval', $examined)));
        self::assertGreaterThan(0, max(array_map('intval', $returned)));
        $this->assertLookupPlansAvoidCollectionScans($explain);
    }

    /** @return array<int,array<string,mixed>> */
    private function evaluatedPlans(array $explain): array
    {
        $plans = [];
        foreach ([
            $explain['queryPlanner']['winningPlan'] ?? null,
            $explain['executionStats'] ?? null,
        ] as $plan) {
            if (is_array($plan)) {
                $plans[] = $plan;
            }
        }
        foreach ($explain['stages'] ?? [] as $stage) {
            if (! is_array($stage)) {
                continue;
            }
            foreach (['$cursor', '$geoNearCursor'] as $cursorKey) {
                foreach ([
                    $stage[$cursorKey]['queryPlanner']['winningPlan'] ?? null,
                    $stage[$cursorKey]['executionStats'] ?? null,
                ] as $plan) {
                    if (is_array($plan)) {
                        $plans[] = $plan;
                    }
                }
            }
        }

        return $plans;
    }

    private function assertLookupPlansAvoidCollectionScans(array $explain): void
    {
        $lookups = array_values(array_filter(
            $explain['stages'] ?? [],
            static fn (mixed $stage): bool => is_array($stage) && isset($stage['$lookup']),
        ));
        foreach ($lookups as $lookup) {
            self::assertSame(0, (int) ($lookup['collectionScans'] ?? -1));
        }
    }

    /** @param array<int,array<string,mixed>> $pipeline */
    private function assertPublishedParentGatePrecedesPageSlice(array $pipeline): void
    {
        $accountLookup = null;
        $pageSlice = null;
        foreach ($pipeline as $index => $stage) {
            if (($stage['$lookup']['from'] ?? null) === 'accounts') {
                $accountLookup = $index;
            }
            if (array_key_exists('$skip', $stage) || array_key_exists('$limit', $stage) || array_key_exists('$facet', $stage)) {
                $pageSlice ??= $index;
            }
        }

        self::assertIsInt($accountLookup, 'The actual public read must join parent Accounts.');
        self::assertIsInt($pageSlice, 'The actual public read must have a bounded page stage.');
        self::assertLessThan($pageSlice, $accountLookup);
        self::assertTrue(
            $this->containsPublicationMatch($pipeline[$accountLookup]['$lookup']['pipeline'] ?? []),
            'The parent join must apply published-account filtering before page slicing.',
        );
    }

    /** @param array<int,array<string,mixed>> $pipeline */
    private function containsPublicationMatch(array $pipeline): bool
    {
        foreach ($pipeline as $stage) {
            if (($stage['$match']['publication.status'] ?? null) === 'published') {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,mixed> */
    private function valuesForKey(mixed $node, string $key): array
    {
        if (! is_array($node)) {
            return [];
        }

        $values = array_key_exists($key, $node) ? [$node[$key]] : [];
        foreach ($node as $value) {
            $values = [...$values, ...$this->valuesForKey($value, $key)];
        }

        return $values;
    }

    private function normalizeBson(mixed $value): mixed
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        } elseif ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeBson($item);
            }
        }

        return $value;
    }
}

final class AccountProfileVisibilityBsonCommandTrace implements CommandSubscriber
{
    /** @var list<array{name:string,command:array<string,mixed>}> */
    private array $commands = [];

    public function commandStarted(CommandStartedEvent $event): void
    {
        $this->commands[] = [
            'name' => $event->getCommandName(),
            'command' => $this->preserveBson($event->getCommand()),
        ];
    }

    public function commandSucceeded(CommandSucceededEvent $event): void {}

    public function commandFailed(CommandFailedEvent $event): void {}

    /** @return array<string,mixed>|null */
    public function first(string $name, string $collection): ?array
    {
        foreach ($this->commands as $entry) {
            if ($entry['name'] === $name && ($entry['command'][$name] ?? null) === $collection) {
                return $entry['command'];
            }
        }

        return null;
    }

    public function count(string $name, string $collection): int
    {
        return count(array_filter(
            $this->commands,
            static fn (array $entry): bool => $entry['name'] === $name
                && ($entry['command'][$name] ?? null) === $collection,
        ));
    }

    /** @return array<string,mixed> */
    private function preserveBson(mixed $value): array
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? $value : [];
    }
}
