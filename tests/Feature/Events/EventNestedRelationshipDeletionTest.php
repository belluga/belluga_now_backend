<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\AccountProfiles\AccountProfileNestedGroupMemberStore;
use App\Application\AccountProfiles\AccountProfileLifecycleService;
use App\Application\AccountProfiles\AccountProfileTransactionContext;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Integration\Events\MapPoiEventProjectionPersistenceAdapter;
use App\Integration\MapPois\MapPoiSourceRefreshAdapter;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Belluga\Events\Application\Events\EventAggregateWriteService;
use Belluga\Events\Application\Events\EventOccurrenceNestedAccountStore;
use Belluga\Events\Application\Transactions\EventTransactionContext;
use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Events\Contracts\EventMapPoiProjectionPersistenceContract;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\MapPois\Application\MapPoiProjectionService;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Belluga\MapPois\Contracts\MapPoiSourceRefreshContract;
use Illuminate\Support\Facades\DB;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Support\MongoCommandTrace;
use Tests\Traits\RefreshLandlordAndTenantDatabases;

/** Focused real-Mongo evidence for the Event-owned half of the deletion invariant. */
final class EventNestedRelationshipDeletionTest extends TestCaseTenant
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
                landlord: ['name' => 'Event deletion landlord'],
                tenant: ['name' => 'Event deletion tenant', 'subdomain' => 'event-deletion'],
                role: ['name' => 'Root', 'permissions' => ['*']],
                user: ['name' => 'Root', 'email' => 'event-delete@example.org', 'password' => 'Secret!234'],
                themeDataSettings: ['brightness_default' => 'light', 'primary_seed_color' => '#fff', 'secondary_seed_color' => '#000'],
                logoSettings: ['light_logo_uri' => '/logos/light.png'],
                pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
                tenantDomains: ['event-deletion.test'],
            ));
            self::$bootstrapped = true;
        }

        Tenant::query()->firstOrFail()->makeCurrent();
        DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->deleteMany([]);
        MapPoi::query()->delete();
        EventOccurrence::withTrashed()->forceDelete();
        Event::withTrashed()->forceDelete();
    }

    public function test_event_delete_purges_every_occurrence_row_and_exact_projection(): void
    {
        [$event, $occurrence, $nested] = $this->fixture('event-delete');
        $eventId = (string) $event->getKey();

        $this->app->make(EventAggregateWriteService::class)->delete($event);

        $this->assertNull(Event::query()->find($eventId));
        $this->assertNull(EventOccurrence::query()->find((string) $occurrence->getKey()));
        $terminalOccurrence = EventOccurrence::withTrashed()->findOrFail((string) $occurrence->getKey());
        $this->assertSame([], collect($terminalOccurrence->own_profile_groups)->all());
        $this->assertSame([], collect($terminalOccurrence->profile_groups)->all());
        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId]));
        $this->assertFalse(MapPoi::query()->where('ref_type', 'event')->where('ref_id', $eventId)->exists());
    }

    public function test_event_delete_terminalizes_every_occurrence_mirror_in_a_multi_occurrence_aggregate(): void
    {
        [$event, $first, $nested] = $this->fixture('event-delete-multiple-occurrences');
        $eventId = (string) $event->getKey();
        $second = EventOccurrence::create([
            'event_id' => $eventId,
            'occurrence_slug' => 'event-delete-multiple-occurrences-second',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(4),
            'own_profile_groups' => [['id' => 'second-group', 'label' => 'Second', 'order' => 0]],
            'profile_groups' => [['id' => 'second-group', 'label' => 'Second', 'order' => 0]],
        ]);
        $secondId = (string) $second->getKey();
        $nested->insertMany([
            ['_id' => "event-delete-multiple-occurrences:{$secondId}:head", 'tenant_id' => (string) Tenant::current()?->getKey(), 'event_id' => $eventId, 'parent_type' => 'event_occurrence', 'parent_id' => $secondId, 'group_key' => 'second-group', 'doc_type' => 'group_head'],
            ['_id' => "event-delete-multiple-occurrences:{$secondId}:member", 'tenant_id' => (string) Tenant::current()?->getKey(), 'event_id' => $eventId, 'parent_type' => 'event_occurrence', 'parent_id' => $secondId, 'group_key' => 'second-group', 'doc_type' => 'member_row', 'nested_profile' => ['id' => 'second-member']],
        ]);

        $this->app->make(EventAggregateWriteService::class)->delete($event);

        foreach ([(string) $first->getKey(), $secondId] as $occurrenceId) {
            $terminal = EventOccurrence::withTrashed()->findOrFail($occurrenceId);
            $this->assertNotNull($terminal->deleted_at);
            $this->assertSame([], collect($terminal->own_profile_groups)->all());
            $this->assertSame([], collect($terminal->profile_groups)->all());
        }
        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId]));
        $this->assertFalse(MapPoi::query()->where('ref_type', 'event')->where('ref_id', $eventId)->exists());
    }

    public function test_runtime_container_uses_the_two_narrow_projection_ports(): void
    {
        $this->assertInstanceOf(
            MapPoiEventProjectionPersistenceAdapter::class,
            $this->app->make(EventMapPoiProjectionPersistenceContract::class),
        );
        $this->assertInstanceOf(
            MapPoiSourceRefreshAdapter::class,
            $this->app->make(MapPoiSourceRefreshContract::class),
        );
    }

    public function test_event_owned_transaction_rolls_back_relationship_projection_and_parent_when_aborted(): void
    {
        [$event, $occurrence, $nested] = $this->fixture('event-rollback');
        $eventId = (string) $event->getKey();
        $occurrenceId = (string) $occurrence->getKey();
        $database = DB::connection('tenant')->getDatabase();
        $before = [
            'event' => $database->selectCollection('events')->findOne(['_id' => new \MongoDB\BSON\ObjectId($eventId)]),
            'occurrence' => $database->selectCollection('event_occurrences')->findOne(['_id' => new \MongoDB\BSON\ObjectId($occurrenceId)]),
            'nested' => iterator_to_array($nested->find(['event_id' => $eventId]), false),
            'map_poi' => $database->selectCollection('map_pois')->findOne(['ref_type' => 'event', 'ref_id' => $eventId]),
        ];
        $this->bindFailingEventProjection('delete');

        try {
            $this->app->make(EventAggregateWriteService::class)->delete($event);
            $this->fail('The event source transaction must abort.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected event map-poi failure', $exception->getMessage());
        }

        $this->assertNotNull(Event::query()->find($eventId));
        $this->assertNotNull(EventOccurrence::query()->find((string) $occurrence->getKey()));
        $this->assertSame(2, $nested->countDocuments(['event_id' => $eventId]));
        $this->assertTrue(MapPoi::query()->where('ref_type', 'event')->where('ref_id', $eventId)->exists());
        $this->assertEquals($before, [
            'event' => $database->selectCollection('events')->findOne(['_id' => new \MongoDB\BSON\ObjectId($eventId)]),
            'occurrence' => $database->selectCollection('event_occurrences')->findOne(['_id' => new \MongoDB\BSON\ObjectId($occurrenceId)]),
            'nested' => iterator_to_array($nested->find(['event_id' => $eventId]), false),
            'map_poi' => $database->selectCollection('map_pois')->findOne(['ref_type' => 'event', 'ref_id' => $eventId]),
        ]);
    }

    public function test_event_update_rolls_back_parent_occurrence_relationships_and_projection_on_real_projection_failure(): void
    {
        [$event, $occurrence, $nested] = $this->fixture('event-upsert-rollback');
        $eventId = (string) $event->getKey();
        $occurrenceId = (string) $occurrence->getKey();
        $database = DB::connection('tenant')->getDatabase();
        $before = [
            'event' => $database->selectCollection('events')->findOne(['_id' => new \MongoDB\BSON\ObjectId($eventId)]),
            'occurrence' => $database->selectCollection('event_occurrences')->findOne(['_id' => new \MongoDB\BSON\ObjectId($occurrenceId)]),
            'nested' => iterator_to_array($nested->find(['event_id' => $eventId], ['sort' => ['_id' => 1]]), false),
            'map_poi' => $database->selectCollection('map_pois')->findOne(['ref_type' => 'event', 'ref_id' => $eventId]),
        ];
        $this->bindFailingEventProjection('persist');

        try {
            $this->app->make(EventAggregateWriteService::class)->update(
                $event,
                ['title' => 'Must roll back', 'content' => 'Must roll back'],
                [[
                    'occurrence_id' => (string) $occurrence->getKey(),
                    'date_time_start' => now()->addDay()->toISOString(),
                    'date_time_end' => now()->addDays(2)->toISOString(),
                ]],
            );
            $this->fail('The projection seam failure must abort the aggregate update.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected event map-poi failure', $exception->getMessage());
        }

        $this->assertSame('event-upsert-rollback', Event::query()->findOrFail($eventId)->title);
        $this->assertNotNull(EventOccurrence::query()->find((string) $occurrence->getKey()));
        $this->assertSame(2, $nested->countDocuments(['event_id' => $eventId]));
        $this->assertTrue(MapPoi::query()->where('ref_type', 'event')->where('ref_id', $eventId)->exists());
        $this->assertEquals($before, [
            'event' => $database->selectCollection('events')->findOne(['_id' => new \MongoDB\BSON\ObjectId($eventId)]),
            'occurrence' => $database->selectCollection('event_occurrences')->findOne(['_id' => new \MongoDB\BSON\ObjectId($occurrenceId)]),
            'nested' => iterator_to_array($nested->find(['event_id' => $eventId], ['sort' => ['_id' => 1]]), false),
            'map_poi' => $database->selectCollection('map_pois')->findOne(['ref_type' => 'event', 'ref_id' => $eventId]),
        ]);
    }

    public function test_delete_first_rejects_event_occurrence_member_reinsert_beneath_terminal_event(): void
    {
        [$event, $occurrence, $nested] = $this->fixture('event-reinsert');
        $eventId = (string) $event->getKey();
        $account = Account::create(['name' => 'Reinsert member', 'document' => 'EVENT-REINSERT-MEMBER']);
        $account->delete();
        $member = AccountProfile::create([
            'account_id' => (string) $account->getKey(), 'profile_type' => 'artist',
            'display_name' => 'Reinsert member', 'is_active' => true,
        ]);
        $this->app->make(EventAggregateWriteService::class)->delete($event);

        try {
            $this->app->make(EventTransactionRunner::class)->run(
                fn (EventTransactionContext $context) => $this->app->make(EventOccurrenceNestedAccountStore::class)
                    ->replaceOccurrenceGroupMembers($context, $occurrence, 'group', [(string) $member->getKey()]),
            );
            $this->fail('A terminal Event must reject the reinsert.');
        } catch (NotFoundHttpException) {
            // Expected: the in-session Event touch is the admission fence.
        }
        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId]));
    }

    public function test_terminal_member_profile_purges_event_occurrence_row_and_rejects_reinsert(): void
    {
        [$event, $occurrence, $nested] = $this->fixture('terminal-member-event-parent');
        $eventId = (string) $event->getKey();
        $occurrenceId = (string) $occurrence->getKey();
        $member = $this->memberProfile('terminal-member-event-parent');
        $memberId = (string) $member->getKey();
        $nested->insertOne([
            '_id' => "accounts-nested:member:event_occurrence:{$occurrenceId}:group:{$memberId}",
            'tenant_id' => (string) Tenant::current()?->getKey(), 'event_id' => $eventId,
            'parent_type' => 'event_occurrence', 'parent_id' => $occurrenceId,
            'group_key' => 'group', 'doc_type' => 'member_row', 'nested_profile' => ['id' => $memberId],
        ]);

        $this->app->make(AccountProfileLifecycleService::class)->delete($member, 'terminal-member-event-parent');
        $this->assertSame(0, $nested->countDocuments(['nested_profile.id' => $memberId]));
        $this->assertSame(1, $nested->countDocuments(['event_id' => $eventId, 'doc_type' => 'group_head']));

        try {
            $this->app->make(EventTransactionRunner::class)->run(
                fn (EventTransactionContext $context) => $this->app->make(EventOccurrenceNestedAccountStore::class)
                    ->replaceOccurrenceGroupMembers($context, $occurrence, 'group', [$memberId]),
            );
            $this->fail('A terminal member Profile must reject physical reinsert beneath a live Event occurrence.');
        } catch (NotFoundHttpException) {
            // Expected: member source-liveness admission rejects the terminal Profile.
        }

        $this->assertSame(0, $nested->countDocuments(['nested_profile.id' => $memberId]));
        $this->assertSame(1, $nested->countDocuments(['event_id' => $eventId, 'doc_type' => 'group_head']));
    }

    public function test_terminal_occurrence_rejects_reinsert_after_its_removal_from_the_event(): void
    {
        [$event, $removed, $nested] = $this->fixture('terminal-occurrence');
        $eventId = (string) $event->getKey();
        $removedId = (string) $removed->getKey();
        $survivor = EventOccurrence::create([
            'event_id' => $eventId,
            'occurrence_slug' => 'terminal-occurrence-survivor',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(4),
        ]);
        $member = $this->memberProfile('terminal-occurrence');

        $this->app->make(EventAggregateWriteService::class)->update(
            $event,
            ['title' => (string) $event->title, 'content' => (string) $event->content],
            [$this->occurrenceUpdatePayload($survivor)],
        );

        $this->assertNotNull(EventOccurrence::withTrashed()->findOrFail($removedId)->deleted_at);
        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId, 'parent_id' => $removedId]));

        try {
            $this->app->make(EventTransactionRunner::class)->run(
                fn (EventTransactionContext $context) => $this->app->make(EventOccurrenceNestedAccountStore::class)
                    ->replaceOccurrenceGroupMembers($context, $removed, 'group', [(string) $member->getKey()]),
            );
            $this->fail('A terminal occurrence must reject a relationship reinsert after reconciliation removal.');
        } catch (NotFoundHttpException) {
            // Expected: the in-session occurrence touch is the admission fence.
        }

        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId, 'parent_id' => $removedId]));
        $this->assertNotNull(EventOccurrence::query()->find((string) $survivor->getKey()));
    }

    public function test_terminal_event_repair_cannot_recreate_occurrence_relationship_rows(): void
    {
        [$event, , $nested] = $this->fixture('terminal-event-repair');
        $eventId = (string) $event->getKey();

        $this->app->make(EventAggregateWriteService::class)->delete($event);
        $terminal = Event::withTrashed()->findOrFail($eventId);
        $this->app->make(EventAggregateWriteService::class)->repairOccurrences($terminal);

        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId]));
        $this->assertSame(0, EventOccurrence::query()->where('event_id', $eventId)->count());
        $this->assertFalse(MapPoi::query()->where('ref_type', 'event')->where('ref_id', $eventId)->exists());
    }

    public function test_create_occurrence_group_is_the_explicit_head_creation_path(): void
    {
        [$event, $occurrence, $nested] = $this->fixture('event-group-create');
        $eventId = (string) $event->getKey();
        $occurrenceId = (string) $occurrence->getKey();

        $this->app->make(EventAggregateWriteService::class)
            ->createOccurrenceGroup($event, $occurrence, 'Created group');

        $created = $nested->findOne([
            'event_id' => $eventId,
            'parent_id' => $occurrenceId,
            'group_label' => 'Created group',
            'doc_type' => 'group_head',
        ]);
        $this->assertNotNull($created);
        $createdId = (string) $created['group_key'];
        $this->assertSame(1, $nested->countDocuments([
            'event_id' => $eventId,
            'parent_id' => $occurrenceId,
            'group_key' => $createdId,
            'doc_type' => 'group_head',
        ]));
        $this->assertNotNull(
            collect(EventOccurrence::query()->findOrFail($occurrenceId)->own_profile_groups)
                ->firstWhere('id', $createdId),
        );
        $this->assertNotNull(
            collect(EventOccurrence::query()->findOrFail($occurrenceId)->profile_groups)
                ->firstWhere('id', $createdId),
        );
    }

    public function test_direct_occurrence_group_delete_keeps_sibling_mutation_and_rejects_target_reinsert(): void
    {
        [$event, $occurrence, $nested] = $this->fixture('event-group-race');
        $eventId = (string) $event->getKey();
        $occurrenceId = (string) $occurrence->getKey();
        $tenantId = (string) Tenant::current()?->getKey();
        $nested->deleteMany(['event_id' => $eventId]);
        foreach ([['id' => 'target', 'label' => 'Target', 'order' => 0], ['id' => 'sibling', 'label' => 'Sibling', 'order' => 1]] as $group) {
            $nested->insertOne([
                '_id' => "accounts-nested:head:event_occurrence:{$occurrenceId}:{$group['id']}",
                'tenant_id' => $tenantId, 'event_id' => $eventId, 'parent_type' => 'event_occurrence',
                'parent_id' => $occurrenceId, 'group_key' => $group['id'], 'group_label' => $group['label'],
                'group_order' => $group['order'], 'doc_type' => 'group_head',
            ]);
        }
        DB::connection('tenant')->getDatabase()->selectCollection('event_occurrences')->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId($occurrenceId)],
            ['$set' => [
                'own_profile_groups' => [['_id' => 'target', 'label' => 'Target'], ['_id' => 'sibling', 'label' => 'Sibling']],
                'profile_groups' => [['_id' => 'target', 'label' => 'Target'], ['_id' => 'sibling', 'label' => 'Sibling']],
            ]],
        );
        $account = Account::create(['name' => 'Group race member', 'document' => 'EVENT-GROUP-RACE-MEMBER']);
        $account->delete();
        $member = AccountProfile::create(['account_id' => (string) $account->getKey(), 'profile_type' => 'artist', 'display_name' => 'Group race member', 'is_active' => true]);
        $secondAccount = Account::create(['name' => 'Second sibling member', 'document' => 'EVENT-GROUP-SECOND-MEMBER']);
        $secondAccount->delete();
        $secondMember = AccountProfile::create(['account_id' => (string) $secondAccount->getKey(), 'profile_type' => 'artist', 'display_name' => 'Second sibling member', 'is_active' => true]);
        $targetAccount = Account::create(['name' => 'Target member', 'document' => 'EVENT-GROUP-TARGET-MEMBER']);
        $targetAccount->delete();
        $targetMember = AccountProfile::create(['account_id' => (string) $targetAccount->getKey(), 'profile_type' => 'artist', 'display_name' => 'Target member', 'is_active' => true]);
        $nested->insertMany([
            ['_id' => "accounts-nested:member:event_occurrence:{$occurrenceId}:target:{$targetMember->getKey()}", 'tenant_id' => $tenantId, 'event_id' => $eventId, 'parent_type' => 'event_occurrence', 'parent_id' => $occurrenceId, 'group_key' => 'target', 'doc_type' => 'member_row', 'nested_profile' => ['id' => (string) $targetMember->getKey()]],
            ['_id' => "accounts-nested:member:event_occurrence:{$occurrenceId}:sibling:{$member->getKey()}", 'tenant_id' => $tenantId, 'event_id' => $eventId, 'parent_type' => 'event_occurrence', 'parent_id' => $occurrenceId, 'group_key' => 'sibling', 'doc_type' => 'member_row', 'nested_profile' => ['id' => (string) $member->getKey()]],
        ]);
        $targetMemberBefore = $targetMember->fresh()?->getAttributes();
        $siblingSnapshot = function () use ($nested, $occurrenceId, $eventId): array {
            $freshOccurrence = EventOccurrence::query()->findOrFail($occurrenceId);

            return [
                'head' => $nested->findOne(['event_id' => $eventId, 'group_key' => 'sibling', 'doc_type' => 'group_head']),
                'members' => iterator_to_array($nested->find(
                    ['event_id' => $eventId, 'group_key' => 'sibling', 'doc_type' => 'member_row'],
                    ['sort' => ['_id' => 1]],
                ), false),
                'own_mirror' => collect($freshOccurrence->own_profile_groups)->firstWhere('id', 'sibling'),
                'public_mirror' => collect($freshOccurrence->profile_groups)->firstWhere('id', 'sibling'),
            ];
        };
        $siblingBeforeDelete = $siblingSnapshot();

        $this->app->make(EventAggregateWriteService::class)->deleteOccurrenceGroup($event, $occurrence, 'target');
        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId, 'group_key' => 'target']));
        $this->assertEquals($siblingBeforeDelete, $siblingSnapshot());

        $trace = $this->captureMongoCommands(fn () => $this->app->make(EventTransactionRunner::class)->run(
            fn (EventTransactionContext $context) => $this->app->make(EventOccurrenceNestedAccountStore::class)
                ->replaceOccurrenceGroupMembers($context, $occurrence, 'sibling', [(string) $member->getKey(), (string) $secondMember->getKey()]),
        ));
        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId, 'group_key' => 'target']));
        $this->assertSame(3, $nested->countDocuments(['event_id' => $eventId, 'group_key' => 'sibling']));
        $memberAdmissions = array_values(array_filter(
            $trace->updateOperationsForCollection('account_profiles'),
            static fn (array $operation): bool => $operation['multi'] && isset($operation['filter']['_id']['$in']),
        ));
        $this->assertCount(1, $memberAdmissions, 'Member admission must be one updateMany query.');
        $freshOccurrence = EventOccurrence::query()->findOrFail($occurrenceId);
        $this->assertSame(['sibling'], collect($freshOccurrence->own_profile_groups)->pluck('id')->all());
        $this->assertSame(['sibling'], collect($freshOccurrence->profile_groups)->pluck('id')->all());
        $this->assertEquals($targetMemberBefore, $targetMember->fresh()?->getAttributes());
        $siblingAfterMutation = $siblingSnapshot();

        try {
            $this->app->make(EventTransactionRunner::class)->run(
                fn (EventTransactionContext $context) => $this->app->make(EventOccurrenceNestedAccountStore::class)
                    ->replaceOccurrenceGroupMembers($context, $occurrence, 'target', [(string) $member->getKey()]),
            );
            $this->fail('The terminal target group must reject a reinsert.');
        } catch (NotFoundHttpException) {
            // Expected: the deleted group head is the admission fence.
        }
        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId, 'group_key' => 'target']));
        $this->assertEquals($siblingAfterMutation, $siblingSnapshot());
    }

    public function test_event_update_removing_one_occurrence_purges_only_its_graph_and_mirrors(): void
    {
        [$event, $removed, $nested] = $this->fixture('event-remove-occurrence');
        $eventId = (string) $event->getKey();
        $removedId = (string) $removed->getKey();
        $survivor = EventOccurrence::create([
            'event_id' => $eventId,
            'occurrence_slug' => 'survivor-'.bin2hex(random_bytes(4)),
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(4),
            'own_profile_groups' => [['_id' => 'survivor-group', 'label' => 'Survivor']],
            'profile_groups' => [['_id' => 'survivor-group', 'label' => 'Survivor']],
        ]);
        $survivorId = (string) $survivor->getKey();
        $nested->insertOne([
            '_id' => 'event-remove-survivor-head', 'tenant_id' => (string) Tenant::current()?->getKey(),
            'event_id' => $eventId, 'parent_type' => 'event_occurrence', 'parent_id' => $survivorId,
            'group_key' => 'survivor-group', 'group_label' => 'Survivor', 'doc_type' => 'group_head',
        ]);
        $removed->forceFill([
            'own_profile_groups' => [['_id' => 'group', 'label' => 'Removed']],
            'profile_groups' => [['_id' => 'group', 'label' => 'Removed']],
        ])->save();

        $this->app->make(EventAggregateWriteService::class)->update(
            $event,
            ['title' => (string) $event->title, 'content' => (string) $event->content],
            [[
                'occurrence_id' => $survivorId,
                'date_time_start' => now()->addDays(3)->toISOString(),
                'date_time_end' => now()->addDays(4)->toISOString(),
            ]],
        );

        $this->assertSame(0, $nested->countDocuments(['event_id' => $eventId, 'parent_id' => $removedId]));
        $this->assertSame(1, $nested->countDocuments(['event_id' => $eventId, 'parent_id' => $survivorId]));
        $this->assertNotNull(EventOccurrence::query()->find($survivorId));
        $removedAfter = EventOccurrence::withTrashed()->findOrFail($removedId);
        $this->assertNotNull($removedAfter->deleted_at);
        $this->assertSame([], collect($removedAfter->own_profile_groups)->all());
        $this->assertSame([], collect($removedAfter->profile_groups)->all());
    }

    public function test_event_cleanup_is_database_exact_when_another_tenant_has_colliding_logical_ids(): void
    {
        $primary = Tenant::current();
        $this->assertNotNull($primary);
        [$event, $occurrence] = $this->fixture('event-tenant-collision');
        $eventId = (string) $event->getKey();
        $occurrenceId = (string) $occurrence->getKey();
        $secondary = Tenant::create(['name' => 'Event deletion isolation', 'subdomain' => 'event-deletion-isolation']);

        try {
            $secondary->makeCurrent();
            $secondaryDb = DB::connection('tenant')->getDatabase();
            $secondaryDb->selectCollection('events')->insertOne([
                '_id' => new \MongoDB\BSON\ObjectId($eventId), 'title' => 'Secondary collision', 'deleted_at' => null,
            ]);
            $secondaryDb->selectCollection('event_occurrences')->insertOne([
                '_id' => new \MongoDB\BSON\ObjectId($occurrenceId), 'event_id' => $eventId,
                'occurrence_slug' => 'secondary-collision', 'deleted_at' => null,
            ]);
            $secondaryDb->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->insertOne([
                '_id' => 'secondary-event-collision', 'event_id' => $eventId, 'parent_type' => 'event_occurrence',
                'parent_id' => $occurrenceId, 'group_key' => 'collision', 'doc_type' => 'group_head',
            ]);
            $secondaryDb->selectCollection('map_pois')->insertOne([
                '_id' => 'secondary-event-collision-map', 'ref_type' => 'event', 'ref_id' => $eventId,
            ]);
            $before = [
                'event' => $secondaryDb->selectCollection('events')->findOne(['_id' => new \MongoDB\BSON\ObjectId($eventId)]),
                'occurrence' => $secondaryDb->selectCollection('event_occurrences')->findOne(['_id' => new \MongoDB\BSON\ObjectId($occurrenceId)]),
                'nested' => $secondaryDb->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->findOne(['_id' => 'secondary-event-collision']),
                'map' => $secondaryDb->selectCollection('map_pois')->findOne(['_id' => 'secondary-event-collision-map']),
            ];

            $primary->makeCurrent();
            $this->app->make(EventAggregateWriteService::class)->delete($event);
            $this->assertNull(Event::query()->find($eventId));

            $secondary->makeCurrent();
            $after = [
                'event' => $secondaryDb->selectCollection('events')->findOne(['_id' => new \MongoDB\BSON\ObjectId($eventId)]),
                'occurrence' => $secondaryDb->selectCollection('event_occurrences')->findOne(['_id' => new \MongoDB\BSON\ObjectId($occurrenceId)]),
                'nested' => $secondaryDb->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION)->findOne(['_id' => 'secondary-event-collision']),
                'map' => $secondaryDb->selectCollection('map_pois')->findOne(['_id' => 'secondary-event-collision-map']),
            ];
            $this->assertEquals($before, $after);
        } finally {
            $primary->makeCurrent();
        }
    }


    /** @return array{0:Event,1:EventOccurrence,2:\MongoDB\Collection} */
    private function fixture(string $prefix): array
    {
        $event = Event::create([
            'title' => $prefix,
            'content' => 'Nested relationship deletion fixture',
            'location' => ['mode' => 'online'],
            'publication' => ['status' => 'draft'],
            'is_active' => true,
        ]);
        $eventId = (string) $event->getKey();
        $occurrence = EventOccurrence::create([
            'event_id' => $eventId,
            'occurrence_slug' => $prefix.'-occurrence',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'own_profile_groups' => [['id' => 'group', 'label' => 'Group', 'order' => 0]],
            'profile_groups' => [['id' => 'group', 'label' => 'Group', 'order' => 0]],
        ]);
        $tenantId = (string) Tenant::current()?->getKey();
        $nested = DB::connection('tenant')->getDatabase()->selectCollection(AccountProfileNestedGroupMemberStore::COLLECTION);
        $nested->insertMany([
            ['_id' => 'accounts-nested:head:event_occurrence:'.(string) $occurrence->getKey().':group', 'tenant_id' => $tenantId, 'event_id' => $eventId, 'parent_type' => 'event_occurrence', 'parent_id' => (string) $occurrence->getKey(), 'group_key' => 'group', 'doc_type' => 'group_head'],
            ['_id' => "{$prefix}-member", 'tenant_id' => $tenantId, 'event_id' => $eventId, 'parent_type' => 'event_occurrence', 'parent_id' => (string) $occurrence->getKey(), 'group_key' => 'group', 'doc_type' => 'member_row', 'nested_profile' => ['id' => 'fixture-member']],
        ]);
        MapPoi::create(['ref_type' => 'event', 'ref_id' => $eventId, 'projection_key' => $prefix, 'name' => $prefix]);

        return [$event, $occurrence, $nested];
    }

    private function memberProfile(string $prefix): AccountProfile
    {
        $account = Account::create(['name' => $prefix.' member', 'document' => strtoupper($prefix).'-MEMBER']);
        $account->delete();

        return AccountProfile::create([
            'account_id' => (string) $account->getKey(),
            'profile_type' => 'artist',
            'display_name' => $prefix.' member',
            'is_active' => true,
        ]);
    }

    /** @return array<string, string> */
    private function occurrenceUpdatePayload(EventOccurrence $occurrence): array
    {
        return [
            'occurrence_id' => (string) $occurrence->getKey(),
            'date_time_start' => $occurrence->starts_at->toISOString(),
            'date_time_end' => $occurrence->ends_at->toISOString(),
        ];
    }

    private function bindFailingEventProjection(string $operation): void
    {
        $inner = $this->app->make(EventMapPoiProjectionPersistenceContract::class);
        $this->app->instance(EventMapPoiProjectionPersistenceContract::class, new class($inner, $operation) implements EventMapPoiProjectionPersistenceContract
        {
            public function __construct(
                private readonly EventMapPoiProjectionPersistenceContract $inner,
                private readonly string $operation,
            ) {}

            public function persistForLiveEvent(EventTransactionContext $context, Event $event): void
            {
                $this->inner->persistForLiveEvent($context, $event);
                if ($this->operation === 'persist') {
                    throw new RuntimeException('injected event map-poi failure');
                }
            }

            public function deleteForEvent(EventTransactionContext $context, string $eventId): void
            {
                $this->inner->deleteForEvent($context, $eventId);
                if ($this->operation === 'delete') {
                    throw new RuntimeException('injected event map-poi failure');
                }
            }
        });
        $this->app->forgetInstance(EventAggregateWriteService::class);
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
