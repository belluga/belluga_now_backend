<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileLifecycleService;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileNestedGroupMemberStore;
use App\Application\AccountProfiles\AccountProfileNestedPublicMembersProjectionService;
use App\Application\AccountProfiles\AccountProfileTransactionContext;
use App\Application\AccountProfiles\AccountProfileTransactionRunner;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\TenantProfileType;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Belluga\MapPois\Jobs\UpsertMapPoiFromAccountProfileJob;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Helpers\TenantLabels;
use Tests\Support\MongoCommandTrace;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use MongoDB\Laravel\Connection;

final class AccountProfileNestedRelationshipDeletionTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;

    private static bool $bootstrapped = false;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->app->make(SystemInitializationService::class)->initialize(new InitializationPayload(
                landlord: ['name' => 'Landlord HQ'],
                tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
                role: ['name' => 'Root', 'permissions' => ['*']],
                user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
                themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                logoSettings: ['light_logo_uri' => '/logos/light.png'],
                pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
                tenantDomains: ['tenant-zeta.test'],
            ));
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
        DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->deleteMany([]);
        DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)->deleteMany([]);
        MapPoi::query()->delete();
        AccountProfile::withTrashed()->forceDelete();
        Account::withTrashed()->forceDelete();
    }

    public function test_direct_profile_delete_purges_owned_and_incoming_graph_and_exact_map_poi(): void
    {
        $targetAccount = Account::create(['name' => 'Target Account', 'document' => 'PROFILE-GRAPH-TARGET']);
        $targetAccount->delete();
        $target = AccountProfile::create(['account_id' => (string) $targetAccount->_id, 'profile_type' => 'artist', 'display_name' => 'Target', 'is_active' => true]);
        $survivingAccount = Account::create(['name' => 'Surviving Account', 'document' => 'PROFILE-GRAPH-SURVIVING']);
        $surviving = AccountProfile::create([
            'account_id' => (string) $survivingAccount->_id,
            'profile_type' => 'artist',
            'display_name' => 'Surviving',
            'is_active' => true,
            'nested_profile_groups' => [[
                'id' => 'embedded-only',
                'label' => 'Embedded only legacy group',
                'order' => 0,
                'account_profile_ids' => [(string) $target->getKey()],
            ]],
        ]);
        $targetId = (string) $target->getKey();
        $survivingId = (string) $surviving->getKey();
        $tenantId = (string) Tenant::current()?->getKey();
        $target->forceFill([
            'nested_profile_groups' => [['id' => 'target', 'label' => 'Target', 'order' => 0]],
        ])->save();

        $nested = DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION);
        $nested->insertMany([
            ['_id' => 'target-head', 'tenant_id' => $tenantId, 'parent_type' => 'account_profile', 'parent_id' => $targetId, 'group_key' => 'target', 'doc_type' => 'group_head'],
            ['_id' => 'target-member', 'tenant_id' => $tenantId, 'parent_type' => 'account_profile', 'parent_id' => $targetId, 'group_key' => 'target', 'doc_type' => 'member_row', 'nested_profile' => ['id' => $survivingId]],
            ['_id' => 'incoming-member', 'tenant_id' => $tenantId, 'parent_type' => 'event_occurrence', 'parent_id' => 'occurrence-1', 'event_id' => 'event-1', 'group_key' => 'event-group', 'doc_type' => 'member_row', 'nested_profile' => ['id' => $targetId]],
            ['_id' => 'control-member', 'tenant_id' => $tenantId, 'parent_type' => 'account_profile', 'parent_id' => $survivingId, 'group_key' => 'control', 'doc_type' => 'member_row', 'nested_profile' => ['id' => $survivingId]],
        ]);
        DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)->insertMany([
            ['_id' => 'target-projection', 'tenant_id' => $tenantId, 'parent_profile_id' => $targetId, 'doc_type' => 'group_head'],
            ['_id' => 'incoming-projection', 'tenant_id' => $tenantId, 'parent_profile_id' => $survivingId, 'member_profile_id' => $targetId, 'doc_type' => 'member_edge'],
            ['_id' => 'control-projection', 'tenant_id' => $tenantId, 'parent_profile_id' => $survivingId, 'member_profile_id' => $survivingId, 'doc_type' => 'member_edge'],
        ]);
        MapPoi::create(['ref_type' => 'account_profile', 'ref_id' => $targetId, 'projection_key' => 'target', 'name' => 'Target']);
        MapPoi::create(['ref_type' => 'account_profile', 'ref_id' => $survivingId, 'projection_key' => 'control', 'name' => 'Control']);

        $this->app->make(AccountProfileLifecycleService::class)->delete($target, 'profile-graph-delete');

        $this->assertNull(AccountProfile::query()->find($targetId));
        $this->assertSame([], collect(AccountProfile::withTrashed()->findOrFail($targetId)->nested_profile_groups)->all());
        $this->assertSame(0, $nested->countDocuments(['$or' => [['parent_id' => $targetId], ['nested_profile.id' => $targetId]]]));
        $this->assertSame(1, $nested->countDocuments(['_id' => 'control-member']));
        $projection = DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION);
        $this->assertSame(0, $projection->countDocuments(['$or' => [['parent_profile_id' => $targetId], ['member_profile_id' => $targetId]]]));
        $this->assertSame(1, $projection->countDocuments(['_id' => 'control-projection']));
        $this->assertFalse(MapPoi::query()->where('ref_type', 'account_profile')->where('ref_id', $targetId)->exists());
        $surviving->refresh();
        $this->assertSame([], collect($surviving->nested_profile_groups)->all());
    }

    public function test_profile_delete_rolls_back_parent_graph_and_map_poi_when_the_owner_aborts_after_cleanup(): void
    {
        [$profile, $nested, $projection] = $this->profileGraphFixture('rollback-owner');
        $profileId = (string) $profile->getKey();
        $database = DB::connection('tenant')->getDatabase();
        $before = [
            'profile' => $database->selectCollection('account_profiles')->findOne(['_id' => new \MongoDB\BSON\ObjectId($profileId)]),
            'nested' => iterator_to_array($nested->find(['parent_id' => $profileId]), false),
            'projection' => iterator_to_array($projection->find(['parent_profile_id' => $profileId]), false),
            'map_poi' => $database->selectCollection('map_pois')->findOne(['ref_type' => 'account_profile', 'ref_id' => $profileId]),
        ];
        $survivor = $this->survivingEmbeddedOnlyReference($profile, 'rollback-owner');
        $survivorId = (string) $survivor->getKey();
        $survivorBefore = $database->selectCollection('account_profiles')->findOne(['_id' => new \MongoDB\BSON\ObjectId($survivorId)]);
        $cleanupCommandId = "rollback-owner-command:reference-cleanup:{$survivorId}";
        $this->assertNull($database->selectCollection('account_profile_outbox')->findOne(['command_id' => $cleanupCommandId]));

        try {
            $this->app->make(AccountProfileTransactionRunner::class)->run(
                function (AccountProfileTransactionContext $context) use ($profile): void {
                    $this->app->make(AccountProfileLifecycleService::class)->deleteWithinTransaction(
                        $profile,
                        $context,
                        'rollback-owner-command',
                        enforceLastProfileInvariant: false,
                    );

                    throw new RuntimeException('injected failure after nested cleanup');
                },
            );
            $this->fail('The source-owned transaction must rethrow the injected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected failure after nested cleanup', $exception->getMessage());
        }

        $this->assertNotNull(AccountProfile::query()->find($profileId));
        $this->assertSame(1, $nested->countDocuments(['_id' => 'rollback-owner-head']));
        $this->assertSame(1, $projection->countDocuments(['_id' => 'rollback-owner-projection']));
        $this->assertTrue(MapPoi::query()->where('ref_type', 'account_profile')->where('ref_id', $profileId)->exists());
        $this->assertEquals($before, [
            'profile' => $database->selectCollection('account_profiles')->findOne(['_id' => new \MongoDB\BSON\ObjectId($profileId)]),
            'nested' => iterator_to_array($nested->find(['parent_id' => $profileId]), false),
            'projection' => iterator_to_array($projection->find(['parent_profile_id' => $profileId]), false),
            'map_poi' => $database->selectCollection('map_pois')->findOne(['ref_type' => 'account_profile', 'ref_id' => $profileId]),
        ]);
        $this->assertEquals($survivorBefore, $database->selectCollection('account_profiles')->findOne(['_id' => new \MongoDB\BSON\ObjectId($survivorId)]));
        $this->assertNull($database->selectCollection('account_profile_outbox')->findOne(['command_id' => $cleanupCommandId]));
    }

    public function test_profile_graph_map_poi_delete_rolls_back_when_the_map_poi_stage_fails(): void
    {
        [$profile, $nested, $projection] = $this->profileGraphFixture('rollback-map-poi');
        $profileId = (string) $profile->getKey();
        $database = DB::connection('tenant')->getDatabase();
        $before = [
            'profile' => $database->selectCollection('account_profiles')->findOne(['_id' => new \MongoDB\BSON\ObjectId($profileId)]),
            'nested' => iterator_to_array($nested->find(['parent_id' => $profileId], ['sort' => ['_id' => 1]]), false),
            'projection' => iterator_to_array($projection->find(['parent_profile_id' => $profileId], ['sort' => ['_id' => 1]]), false),
            'map_poi' => $database->selectCollection('map_pois')->findOne(['ref_type' => 'account_profile', 'ref_id' => $profileId]),
        ];
        $survivor = $this->survivingEmbeddedOnlyReference($profile, 'rollback-map-poi');
        $survivorId = (string) $survivor->getKey();
        $survivorBefore = $database->selectCollection('account_profiles')->findOne(['_id' => new \MongoDB\BSON\ObjectId($survivorId)]);
        $cleanupCommandId = "rollback-map-poi-command:reference-cleanup:{$survivorId}";
        $this->assertNull($database->selectCollection('account_profile_outbox')->findOne(['command_id' => $cleanupCommandId]));
        $inner = $this->app->make(MapPoiProjectionService::class);
        $failingProjection = \Mockery::mock(MapPoiProjectionService::class)->makePartial();
        $failingProjection->shouldReceive('deleteByRefsWithinTransaction')
            ->once()
            ->andReturnUsing(function (...$arguments) use ($inner): void {
                $inner->deleteByRefsWithinTransaction(...$arguments);
                throw new RuntimeException('injected map-poi delete failure');
            });
        $this->app->instance(MapPoiProjectionService::class, $failingProjection);
        $this->app->forgetInstance(\App\Application\AccountProfiles\AccountProfileReferenceCleanupService::class);
        $this->app->forgetInstance(AccountProfileLifecycleService::class);

        try {
            $this->app->make(AccountProfileTransactionRunner::class)->run(
                fn (AccountProfileTransactionContext $context) => $this->app->make(AccountProfileLifecycleService::class)
                    ->deleteWithinTransaction($profile, $context, 'rollback-map-poi-command', enforceLastProfileInvariant: false),
            );
            $this->fail('The MapPoi-stage failure must abort the source transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected map-poi delete failure', $exception->getMessage());
        }

        $this->assertNotNull(AccountProfile::query()->find($profileId));
        $this->assertSame(1, $nested->countDocuments(['_id' => 'rollback-map-poi-head']));
        $this->assertSame(1, $projection->countDocuments(['_id' => 'rollback-map-poi-projection']));
        $this->assertTrue(MapPoi::query()->where('ref_type', 'account_profile')->where('ref_id', $profileId)->exists());
        $this->assertEquals($before, [
            'profile' => $database->selectCollection('account_profiles')->findOne(['_id' => new \MongoDB\BSON\ObjectId($profileId)]),
            'nested' => iterator_to_array($nested->find(['parent_id' => $profileId], ['sort' => ['_id' => 1]]), false),
            'projection' => iterator_to_array($projection->find(['parent_profile_id' => $profileId], ['sort' => ['_id' => 1]]), false),
            'map_poi' => $database->selectCollection('map_pois')->findOne(['ref_type' => 'account_profile', 'ref_id' => $profileId]),
        ]);
        $this->assertEquals($survivorBefore, $database->selectCollection('account_profiles')->findOne(['_id' => new \MongoDB\BSON\ObjectId($survivorId)]));
        $this->assertNull($database->selectCollection('account_profile_outbox')->findOne(['command_id' => $cleanupCommandId]));
    }

    public function test_delete_first_rejects_profile_group_member_reinsert_beneath_a_terminal_parent(): void
    {
        $account = Account::create(['name' => 'Terminal parent', 'document' => 'TERMINAL-PARENT']);
        $account->delete();
        $parent = AccountProfile::create(['account_id' => (string) $account->_id, 'profile_type' => 'artist', 'display_name' => 'Terminal parent', 'is_active' => true]);
        $memberAccount = Account::create(['name' => 'Live member', 'document' => 'LIVE-MEMBER']);
        $memberAccount->delete();
        $member = AccountProfile::create(['account_id' => (string) $memberAccount->_id, 'profile_type' => 'artist', 'display_name' => 'Live member', 'is_active' => true]);
        $parentId = (string) $parent->getKey();
        $groupId = 'terminal-group';
        DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->insertOne([
            '_id' => "accounts-nested:head:account_profile:{$parentId}:{$groupId}",
            'tenant_id' => (string) Tenant::current()?->getKey(), 'parent_type' => 'account_profile',
            'parent_id' => $parentId, 'group_key' => $groupId, 'doc_type' => 'group_head',
        ]);
        $parent->delete();

        try {
            $this->app->make(AccountProfileTransactionRunner::class)->run(
                fn (AccountProfileTransactionContext $context) => $this->app->make(AccountProfileNestedGroupMemberStore::class)
                    ->replaceGroupMembersWithinContext($context, $parent, $groupId, [(string) $member->getKey()]),
            );
            $this->fail('A terminal Profile parent must reject the member reinsert.');
        } catch (ConcurrencyConflictException) {
            // Expected: the in-session parent touch is the admission fence.
        }

        $this->assertSame(0, DB::connection('tenant')->getDatabase()
            ->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)
            ->countDocuments([
                'parent_id' => $parentId,
                'group_key' => $groupId,
                'doc_type' => 'member_row',
                'nested_profile.id' => (string) $member->getKey(),
            ]));
    }

    public function test_terminal_member_profile_purges_profile_parent_row_and_rejects_reinsert(): void
    {
        [$parent] = $this->liveProfilePair('terminal-member-profile-parent');
        [, $member] = $this->liveProfilePair('terminal-member-profile');
        $parentId = (string) $parent->getKey();
        $memberId = (string) $member->getKey();
        $groupId = 'terminal-member';
        $groups = [['id' => $groupId, 'label' => 'Terminal member', 'order' => 0]];
        $parent->forceFill(['nested_profile_groups' => $groups])->save();
        DB::connection('tenant')->getDatabase()->selectCollection('account_profiles')->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId($parentId)],
            ['$set' => ['nested_profile_groups' => $groups]],
        );
        $nested = DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION);
        $nested->insertMany([
            ['_id' => "accounts-nested:head:account_profile:{$parentId}:{$groupId}", 'tenant_id' => (string) Tenant::current()?->getKey(), 'parent_type' => 'account_profile', 'parent_id' => $parentId, 'group_key' => $groupId, 'doc_type' => 'group_head'],
            ['_id' => "accounts-nested:member:account_profile:{$parentId}:{$groupId}:{$memberId}", 'tenant_id' => (string) Tenant::current()?->getKey(), 'parent_type' => 'account_profile', 'parent_id' => $parentId, 'group_key' => $groupId, 'doc_type' => 'member_row', 'nested_profile' => ['id' => $memberId]],
        ]);

        $this->app->make(AccountProfileLifecycleService::class)->delete($member, 'terminal-member-profile');
        $this->assertSame(0, $nested->countDocuments(['nested_profile.id' => $memberId]));
        $this->assertSame(1, $nested->countDocuments(['parent_id' => $parentId, 'doc_type' => 'group_head']));

        try {
            $this->app->make(AccountProfileTransactionRunner::class)->run(
                fn (AccountProfileTransactionContext $context) => $this->app->make(AccountProfileNestedGroupMemberStore::class)
                    ->replaceGroupMembersWithinContext($context, $parent, $groupId, [$memberId]),
            );
            $this->fail('A terminal member Profile must reject physical reinsert beneath a live Profile parent.');
        } catch (ConcurrencyConflictException) {
            // Expected: member source-liveness admission rejects the terminal Profile.
        }

        $this->assertSame(0, $nested->countDocuments(['nested_profile.id' => $memberId]));
        $this->assertSame(1, $nested->countDocuments(['parent_id' => $parentId, 'doc_type' => 'group_head']));
    }

    public function test_direct_profile_group_delete_keeps_sibling_mutation_and_rejects_target_reinsert(): void
    {
        [$parent, $member] = $this->liveProfilePair('profile-group-race');
        [, $secondMember] = $this->liveProfilePair('profile-group-second-member');
        [, $targetMember] = $this->liveProfilePair('profile-group-target-member');
        $parentId = (string) $parent->getKey();
        $groups = [
            ['id' => 'target', 'label' => 'Target', 'order' => 0, 'member_count' => 0],
            ['id' => 'sibling', 'label' => 'Sibling', 'order' => 1, 'member_count' => 0],
        ];
        $parent->forceFill(['nested_profile_groups' => $groups])->save();
        DB::connection('tenant')->getDatabase()->selectCollection('account_profiles')->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId($parentId)],
            ['$set' => ['nested_profile_groups' => $groups]],
        );
        $nested = DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION);
        // The destructive owner deliberately uses the persisted `id` mirror
        // selector. Keep this fixture raw so it exercises that exact contract.
        DB::connection('tenant')->getDatabase()->selectCollection('account_profiles')->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId($parentId)],
            ['$set' => ['nested_profile_groups' => $groups]],
        );
        foreach ($groups as $group) {
            $nested->insertOne([
                '_id' => "accounts-nested:head:account_profile:{$parentId}:{$group['id']}",
                'tenant_id' => (string) Tenant::current()?->getKey(), 'parent_type' => 'account_profile',
                'parent_id' => $parentId, 'group_key' => $group['id'], 'group_label' => $group['label'],
                'group_order' => $group['order'], 'doc_type' => 'group_head',
            ]);
        }
        $nested->insertMany([
            ['_id' => "accounts-nested:member:account_profile:{$parentId}:target:{$targetMember->getKey()}", 'tenant_id' => (string) Tenant::current()?->getKey(), 'parent_type' => 'account_profile', 'parent_id' => $parentId, 'group_key' => 'target', 'doc_type' => 'member_row', 'nested_profile' => ['id' => (string) $targetMember->getKey()]],
            ['_id' => "accounts-nested:member:account_profile:{$parentId}:sibling:{$member->getKey()}", 'tenant_id' => (string) Tenant::current()?->getKey(), 'parent_type' => 'account_profile', 'parent_id' => $parentId, 'group_key' => 'sibling', 'doc_type' => 'member_row', 'nested_profile' => ['id' => (string) $member->getKey()]],
        ]);
        $projection = DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION);
        $projection->insertMany([
            ['_id' => 'profile-target-projection', 'tenant_id' => (string) Tenant::current()?->getKey(), 'parent_profile_id' => $parentId, 'group_id' => 'target', 'member_profile_id' => (string) $targetMember->getKey()],
            ['_id' => 'profile-sibling-projection', 'tenant_id' => (string) Tenant::current()?->getKey(), 'parent_profile_id' => $parentId, 'group_id' => 'sibling', 'member_profile_id' => (string) $member->getKey()],
        ]);
        $targetMemberBefore = $targetMember->fresh()?->getAttributes();
        $siblingSnapshot = function () use ($nested, $projection, $parentId): array {
            $profile = AccountProfile::query()->findOrFail($parentId);

            return [
                'head' => $nested->findOne(['parent_id' => $parentId, 'group_key' => 'sibling', 'doc_type' => 'group_head']),
                'members' => iterator_to_array($nested->find(
                    ['parent_id' => $parentId, 'group_key' => 'sibling', 'doc_type' => 'member_row'],
                    ['sort' => ['_id' => 1]],
                ), false),
                'mirror' => collect($profile->nested_profile_groups)->firstWhere('id', 'sibling'),
                'projection' => iterator_to_array($projection->find(
                    ['parent_profile_id' => $parentId, 'group_id' => 'sibling'],
                    ['sort' => ['_id' => 1]],
                ), false),
            ];
        };
        $siblingBeforeDelete = $siblingSnapshot();

        $this->app->make(AccountProfileManagementService::class)->deleteNestedGroup($parent, 'target');

        $this->assertSame(0, $nested->countDocuments(['parent_id' => $parentId, 'group_key' => 'target']));
        $this->assertSame(0, $projection->countDocuments(['parent_profile_id' => $parentId, 'group_id' => 'target']));
        $this->assertEquals($siblingBeforeDelete, $siblingSnapshot());

        $trace = $this->captureMongoCommands(fn () => $this->app->make(AccountProfileTransactionRunner::class)->run(
            fn (AccountProfileTransactionContext $context) => $this->app->make(AccountProfileNestedGroupMemberStore::class)
                ->replaceGroupMembersWithinContext($context, $parent, 'sibling', [(string) $member->getKey(), (string) $secondMember->getKey()]),
        ));
        $this->assertSame(0, $nested->countDocuments(['parent_id' => $parentId, 'group_key' => 'target']));
        $this->assertSame(3, $nested->countDocuments(['parent_id' => $parentId, 'group_key' => 'sibling']));
        $memberAdmissions = array_values(array_filter(
            $trace->updateOperationsForCollection('account_profiles'),
            static fn (array $operation): bool => $operation['multi'] && isset($operation['filter']['_id']['$in']),
        ));
        $this->assertCount(1, $memberAdmissions, 'Member admission must be one updateMany query.');
        $this->assertSame(0, $projection->countDocuments(['parent_profile_id' => $parentId, 'group_id' => 'target']));
        $this->assertSame(1, $projection->countDocuments(['parent_profile_id' => $parentId, 'group_id' => 'sibling']));
        $this->assertSame(['sibling'], collect(AccountProfile::query()->findOrFail($parentId)->nested_profile_groups)->pluck('id')->all());
        $this->assertEquals($targetMemberBefore, $targetMember->fresh()?->getAttributes());
        $siblingAfterMutation = $siblingSnapshot();

        try {
            $this->app->make(AccountProfileTransactionRunner::class)->run(
                fn (AccountProfileTransactionContext $context) => $this->app->make(AccountProfileNestedGroupMemberStore::class)
                    ->replaceGroupMembersWithinContext($context, $parent, 'target', [(string) $member->getKey()]),
            );
            $this->fail('The terminal target group must reject a reinsert.');
        } catch (ConcurrencyConflictException) {
            // Expected: the deleted group head is the admission fence.
        }
        $this->assertSame(0, $nested->countDocuments(['parent_id' => $parentId, 'group_key' => 'target']));
        $this->assertEquals($siblingAfterMutation, $siblingSnapshot());
    }

    public function test_profile_cleanup_is_tenant_exact_when_another_tenant_has_colliding_logical_ids(): void
    {
        $primary = Tenant::current();
        $this->assertNotNull($primary);
        $account = Account::create(['name' => 'Primary collision account', 'document' => 'PRIMARY-COLLISION']);
        $account->delete();
        $profile = AccountProfile::create(['account_id' => (string) $account->getKey(), 'profile_type' => 'artist', 'display_name' => 'Primary collision profile', 'is_active' => true]);
        $profileId = (string) $profile->getKey();
        $primaryDb = DB::connection('tenant')->getDatabase();
        $primaryDb->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->insertOne([
            '_id' => 'primary-collision', 'tenant_id' => (string) $primary->getKey(),
            'parent_type' => 'account_profile', 'parent_id' => $profileId, 'group_key' => 'collision', 'doc_type' => 'group_head',
        ]);

        $secondary = Tenant::create(['name' => 'Nested deletion isolation', 'subdomain' => 'nested-deletion-isolation']);
        try {
            $secondary->makeCurrent();
            $secondaryDb = DB::connection('tenant')->getDatabase();
            $secondaryDb->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->insertOne([
                '_id' => 'secondary-collision', 'tenant_id' => (string) $secondary->getKey(),
                'parent_type' => 'account_profile', 'parent_id' => $profileId, 'group_key' => 'collision', 'doc_type' => 'group_head',
            ]);
            $secondaryDb->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)->insertOne([
                '_id' => 'secondary-collision-projection', 'tenant_id' => (string) $secondary->getKey(), 'parent_profile_id' => $profileId,
            ]);
            $secondaryDb->selectCollection('map_pois')->insertOne([
                '_id' => 'secondary-collision-map-poi', 'ref_type' => 'account_profile', 'ref_id' => $profileId,
            ]);

            $primary->makeCurrent();
            $this->app->make(AccountProfileLifecycleService::class)->delete($profile, 'tenant-exact-profile-delete');
            $this->assertSame(0, DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->countDocuments(['parent_id' => $profileId]));

            $secondary->makeCurrent();
            $this->assertSame(1, $secondaryDb->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->countDocuments(['_id' => 'secondary-collision']));
            $this->assertSame(1, $secondaryDb->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION)->countDocuments(['_id' => 'secondary-collision-projection']));
            $this->assertSame(1, $secondaryDb->selectCollection('map_pois')->countDocuments(['_id' => 'secondary-collision-map-poi']));
        } finally {
            $primary->makeCurrent();
        }
    }

    public function test_stale_profile_map_poi_job_cannot_resurrect_a_terminal_profile(): void
    {
        [$profile] = $this->profileGraphFixture('stale-profile-job');
        $profileId = (string) $profile->getKey();

        $this->app->make(AccountProfileLifecycleService::class)->delete($profile, 'stale-profile-job-delete');
        (new UpsertMapPoiFromAccountProfileJob($profileId))->handle($this->app->make(\Belluga\MapPois\Application\MapPoiProjectionService::class));

        $this->assertFalse(MapPoi::query()->where('ref_type', 'account_profile')->where('ref_id', $profileId)->exists());
    }

    public function test_profile_map_poi_refresh_committed_before_delete_is_removed_by_terminal_owner(): void
    {
        TenantProfileType::create([
            'type' => 'refreshable-poi', 'label' => 'Refreshable POI', 'allowed_taxonomies' => [],
            'capabilities' => ['is_poi_enabled' => true],
        ]);
        $account = Account::create(['name' => 'Refresh before delete', 'document' => 'REFRESH-BEFORE-DELETE']);
        $profile = AccountProfile::create([
            'account_id' => (string) $account->getKey(), 'profile_type' => 'refreshable-poi',
            'display_name' => 'Refresh before delete', 'is_active' => true,
            'location' => ['type' => 'Point', 'coordinates' => [-40.0, -20.0]],
        ]);
        $profileId = (string) $profile->getKey();

        (new UpsertMapPoiFromAccountProfileJob($profileId))->handle($this->app->make(\Belluga\MapPois\Application\MapPoiProjectionService::class));
        $this->assertTrue(MapPoi::query()->where('ref_type', 'account_profile')->where('ref_id', $profileId)->exists());

        $this->app->make(AccountProfileTransactionRunner::class)->run(
            fn (AccountProfileTransactionContext $context) => $this->app->make(AccountProfileLifecycleService::class)
                ->deleteWithinTransaction($profile, $context, 'refresh-before-profile-delete', enforceLastProfileInvariant: false),
        );

        $this->assertFalse(MapPoi::query()->where('ref_type', 'account_profile')->where('ref_id', $profileId)->exists());
    }

    /** @return array{0:AccountProfile,1:\MongoDB\Collection,2:\MongoDB\Collection} */
    private function profileGraphFixture(string $prefix): array
    {
        $account = Account::create(['name' => "{$prefix} Account", 'document' => strtoupper($prefix).'-ACCOUNT']);
        $account->delete();
        $profile = AccountProfile::create([
            'account_id' => (string) $account->_id,
            'profile_type' => 'artist',
            'display_name' => "{$prefix} Profile",
            'is_active' => true,
            'nested_profile_groups' => [['id' => 'group', 'label' => 'Group', 'order' => 0]],
        ]);
        $profileId = (string) $profile->getKey();
        $tenantId = (string) Tenant::current()?->getKey();
        $nested = DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION);
        $projection = DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedPublicMembersProjectionService::COLLECTION);
        $nested->insertOne([
            '_id' => "{$prefix}-head", 'tenant_id' => $tenantId,
            'parent_type' => 'account_profile', 'parent_id' => $profileId,
            'group_key' => 'group', 'doc_type' => 'group_head',
        ]);
        $projection->insertOne([
            '_id' => "{$prefix}-projection", 'tenant_id' => $tenantId,
            'parent_profile_id' => $profileId, 'doc_type' => 'group_head',
        ]);
        MapPoi::create(['ref_type' => 'account_profile', 'ref_id' => $profileId, 'projection_key' => $prefix, 'name' => $prefix]);

        return [$profile, $nested, $projection];
    }

    private function survivingEmbeddedOnlyReference(AccountProfile $target, string $prefix): AccountProfile
    {
        $account = Account::create(['name' => "{$prefix} survivor account", 'document' => strtoupper($prefix).'-SURVIVOR']);
        $account->delete();

        return AccountProfile::create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => 'artist',
            'display_name' => "{$prefix} survivor",
            'is_active' => true,
            'nested_profile_groups' => [[
                'id' => 'embedded-only',
                'label' => 'Embedded only legacy group',
                'order' => 0,
                'account_profile_ids' => [(string) $target->getKey()],
            ]],
        ]);
    }

    /** @return array{0:AccountProfile,1:AccountProfile} */
    private function liveProfilePair(string $prefix): array
    {
        $parentAccount = Account::create(['name' => "{$prefix} parent", 'document' => strtoupper($prefix).'-PARENT']);
        $parentAccount->delete();
        $memberAccount = Account::create(['name' => "{$prefix} member", 'document' => strtoupper($prefix).'-MEMBER']);
        $memberAccount->delete();

        return [
            AccountProfile::create(['account_id' => (string) $parentAccount->getKey(), 'profile_type' => 'artist', 'display_name' => "{$prefix} parent", 'is_active' => true]),
            AccountProfile::create(['account_id' => (string) $memberAccount->getKey(), 'profile_type' => 'artist', 'display_name' => "{$prefix} member", 'is_active' => true]),
        ];
    }

    private function captureMongoCommands(callable $operation): MongoCommandTrace
    {
        $client = DB::connection('tenant')->getClient();
        $this->assertNotNull($client);
        $trace = new MongoCommandTrace;
        $client->addSubscriber($trace);
        try {
            $operation();
        } finally {
            $client->removeSubscriber($trace);
        }

        return $trace;
    }

}
