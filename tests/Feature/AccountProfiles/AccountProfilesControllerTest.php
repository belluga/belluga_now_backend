<?php

declare(strict_types=1);

namespace Tests\Feature\AccountProfiles;

use App\Application\AccountProfiles\AccountProfileAgendaOccurrencesService;
use App\Application\AccountProfiles\AccountProfileLifecycleService;
use App\Application\AccountProfiles\AccountProfileManagementService;
use App\Application\AccountProfiles\AccountProfileMapPoiOutboxConsumer;
use App\Application\AccountProfiles\AccountProfileOutboxDispatcher;
use App\Application\AccountProfiles\AccountProfileOutboxPublisher;
use App\Application\AccountProfiles\AccountProfileQueryService;
use App\Application\AccountProfiles\AccountProfileTransactionContext;
use App\Application\AccountProfiles\AccountProfileTransactionRetryPolicy;
use App\Application\AccountProfiles\AccountProfileTransactionRunner;
use App\Application\Accounts\AccountManagementService;
use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Exceptions\FoundationControlPlane\ConcurrencyConflictException;
use App\Jobs\Environment\RebuildTenantEnvironmentSnapshotJob;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountRoleTemplate;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\Taxonomy;
use App\Models\Tenants\TaxonomyTerm;
use App\Models\Tenants\TenantProfileType;
use App\Support\Validation\InputConstraints;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\MapPois\Models\Tenants\MapPoi;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Connection;
use RuntimeException;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AccountProfilesControllerTest extends TestCaseTenant
{
    use RefreshLandlordAndTenantDatabases;
    use SeedsTenantAccounts;

    protected TenantLabels $tenant {
        get {
            return $this->landlord->tenant_primary;
        }
    }

    private static bool $bootstrapped = false;

    private Account $account;

    private AccountRoleTemplate $accountRoleTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();

        AccountProfile::query()->delete();
        TaxonomyTerm::query()->delete();
        Taxonomy::query()->delete();

        [$this->account, $this->accountRoleTemplate] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);
        TenantProfileType::query()->delete();
        TenantProfileType::create([
            'type' => 'personal',
            'label' => 'Personal',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => false,
                'is_publicly_navigable' => false,
                'is_favoritable' => false,
                'is_publicly_discoverable' => false,
                'is_poi_enabled' => false,
                'has_events' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'venue',
            'label' => 'Venue',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
                'has_events' => true,
                'has_gallery' => true,
                'has_nested_profile_groups' => true,
            ],
        ]);

        $taxonomy = Taxonomy::create([
            'slug' => 'cuisine',
            'name' => 'Cuisine',
            'applies_to' => ['account_profile', 'event', 'static_asset'],
            'icon' => 'restaurant',
            'color' => '#FFAA00',
        ]);
        TaxonomyTerm::create([
            'taxonomy_id' => (string) $taxonomy->_id,
            'slug' => 'italian',
            'name' => 'Italian',
        ]);
    }

    public function test_account_profile_index_accessible_for_account_user(): void
    {
        $user = $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Profile Viewer',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
        $this->assertTrue(collect($response->json('data'))->every(static fn (array $item): bool => array_key_exists('ownership_state', $item)));
    }

    public function test_profile_update_persists_a_command_receipt_and_outbox_event_for_the_same_request_id(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Outbox Source Venue',
            'is_active' => true,
        ])->fresh();
        $commandId = 'u07a-profile-update-'.uniqid('', true);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profile->_id}",
            ['display_name' => 'Outbox Updated Venue'],
            [...$this->getHeaders(), 'X-Request-Id' => $commandId],
        );

        $response->assertOk();

        $database = DB::connection('tenant')->getDatabase();
        $receipt = $database
            ->selectCollection('account_profile_command_receipts')
            ->findOne(['_id' => $commandId]);
        $outbox = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);

        $this->assertNotNull($receipt);
        $this->assertNotNull($outbox);
        $this->assertSame((string) $profile->_id, (string) ($outbox['profile_id'] ?? ''));
        $this->assertSame('upsert', (string) ($outbox['operation'] ?? ''));
        $this->assertSame($commandId, (string) ($receipt['command_id'] ?? ''));
    }

    public function test_profile_update_replays_the_same_request_id_without_a_second_outbox_event(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Idempotent Outbox Venue',
            'is_active' => true,
        ])->fresh();
        $commandId = 'u07a-profile-replay-'.uniqid('', true);
        $url = "{$this->base_tenant_api_admin}account_profiles/{$profile->_id}";
        $payload = ['display_name' => 'Idempotent Outbox Venue Updated'];
        $headers = [...$this->getHeaders(), 'X-Request-Id' => $commandId];

        $first = $this->patchJson($url, $payload, $headers);
        $second = $this->patchJson($url, $payload, $headers);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.display_name'), $second->json('data.display_name'));
        $this->assertSame(
            1,
            DB::connection('tenant')
                ->getDatabase()
                ->selectCollection('account_profile_outbox')
                ->countDocuments(['command_id' => $commandId]),
        );
    }

    public function test_unknown_commit_reconciles_from_a_durable_command_receipt_without_replaying_the_body(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Unknown Commit Receipt Source',
            'is_active' => true,
            'aggregate_revision' => 1,
        ])->fresh();
        $commandId = 'u07a-unknown-commit-'.uniqid('', true);
        $publisher = app(AccountProfileOutboxPublisher::class);
        $fingerprint = $publisher->fingerprintForUpdate(
            (string) $profile->_id,
            ['display_name' => 'Unknown Commit Receipt Source'],
        );
        $realConnection = DB::connection('tenant');
        $connection = Mockery::mock(Connection::class);
        $unknownCommit = new class extends RuntimeException
        {
            public function __construct()
            {
                parent::__construct('Unknown transaction commit result.');
            }

            public function hasErrorLabel(string $label): bool
            {
                return $label === 'UnknownTransactionCommitResult';
            }
        };
        $commitCalls = 0;
        $bodyCalls = 0;
        $reconciliationCalls = 0;

        DB::shouldReceive('connection')
            ->twice()
            ->with('tenant')
            ->andReturn($connection, $realConnection);
        $connection->shouldReceive('beginTransaction')
            ->once()
            ->andReturnUsing(static function () use ($realConnection): void {
                $realConnection->beginTransaction();
            });
        $connection->shouldReceive('getSession')
            ->once()
            ->andReturnUsing(static fn () => $realConnection->getSession());
        $connection->shouldReceive('getDatabase')
            ->once()
            ->andReturnUsing(static fn () => $realConnection->getDatabase());
        $connection->shouldReceive('commit')
            ->times(3)
            ->andReturnUsing(function () use ($realConnection, $unknownCommit, &$commitCalls): never {
                $commitCalls++;
                if ($commitCalls === 1) {
                    // The transaction committed, but its acknowledgement is indeterminate.
                    $realConnection->commit();
                }

                throw $unknownCommit;
            });

        $result = (new AccountProfileTransactionRunner(new AccountProfileTransactionRetryPolicy))->run(
            function (AccountProfileTransactionContext $context) use ($publisher, $profile, $commandId, $fingerprint, &$bodyCalls): array {
                $bodyCalls++;

                return [
                    'event_id' => $publisher->recordUpsert($context, $profile, $commandId, $fingerprint),
                ];
            },
            function () use ($publisher, $commandId, $fingerprint, &$reconciliationCalls): ?array {
                $reconciliationCalls++;
                $receipt = $publisher->committedReceipt($commandId);
                if ($receipt === null) {
                    return null;
                }

                $publisher->assertReceiptMatches($receipt, $fingerprint);

                return ['event_id' => (string) $receipt['outbox_event_id']];
            },
        );

        $database = $realConnection->getDatabase();
        $receipt = $database
            ->selectCollection('account_profile_command_receipts')
            ->findOne(['_id' => $commandId]);

        $this->assertSame(1, $bodyCalls);
        $this->assertSame(3, $commitCalls);
        $this->assertSame(1, $reconciliationCalls);
        $this->assertNotNull($receipt);
        $this->assertSame((string) ($receipt['outbox_event_id'] ?? ''), $result['event_id']);
        $this->assertSame(
            1,
            $database->selectCollection('account_profile_command_receipts')->countDocuments(['_id' => $commandId]),
        );
        $this->assertSame(
            1,
            $database->selectCollection('account_profile_outbox')->countDocuments(['command_id' => $commandId]),
        );
    }

    public function test_profile_update_conflicts_while_its_account_is_deletion_gated(): void
    {
        $this->markTestSkipped(
            'Deferred to foundation_documentation/todos/active/v0.4.1/TODO-v0.4.1-account-profile-deletion-gate-conflict-response.md during the Tuesday, July 21, 2026 v0.4.0 promotion replay.'
        );

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Deletion Gated Venue',
            'is_active' => true,
        ]);
        $this->account->setAttribute('account_profile_deletion_gate', [
            'attempt_id' => 'u07a-test-deletion-attempt',
            'attempt_generation' => 1,
        ]);
        $this->account->save();

        $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profile->_id}",
            ['display_name' => 'Must Not Persist'],
            [...$this->getHeaders(), 'X-Request-Id' => 'u07a-gated-update-'.uniqid('', true)],
        )->assertConflict();

        $this->assertSame('Deletion Gated Venue', (string) $profile->fresh()->display_name);
    }

    public function test_gallery_update_persists_an_outbox_event_and_conflicts_while_deletion_gated(): void
    {
        $this->markTestSkipped(
            'Deferred to foundation_documentation/todos/active/v0.4.1/TODO-v0.4.1-account-profile-gallery-outbox-durability.md during the Tuesday, July 21, 2026 v0.4.0 promotion replay.'
        );

        Storage::fake('public');

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Outbox Gallery Venue',
            'is_active' => true,
        ]);
        $commandId = 'u07a-gallery-update-'.uniqid('', true);
        $url = "{$this->base_tenant_api_admin}account_profiles/{$profile->_id}/gallery";
        $payload = [
            '_method' => 'PATCH',
            'gallery_groups' => json_encode([
                [
                    'group_id' => 'main',
                    'subtitle' => 'Main',
                    'items' => [
                        [
                            'item_id' => 'entry',
                            'upload' => 'gallery_entry',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'gallery_entry' => UploadedFile::fake()->image('entry.jpg', 1200, 800),
        ];

        $this->withHeaders([
            ...$this->getMultipartHeaders(),
            'X-Request-Id' => $commandId,
        ])->post($url, $payload)->assertOk();

        $outbox = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);
        $this->assertNotNull($outbox);
        $this->assertSame('upsert', $outbox['operation'] ?? null);

        $this->account->setAttribute('account_profile_deletion_gate', [
            'attempt_id' => 'u07a-gallery-gate',
            'attempt_generation' => 1,
        ]);
        $this->account->save();

        $this->withHeaders([
            ...$this->getMultipartHeaders(),
            'X-Request-Id' => 'u07a-gallery-gated-'.uniqid('', true),
        ])->post($url, [
            '_method' => 'PATCH',
            'gallery_groups' => json_encode([], JSON_THROW_ON_ERROR),
        ])->assertConflict();

        $this->assertSame('entry', (string) $profile->fresh()->gallery_groups[0]['items'][0]['item_id']);
    }

    public function test_profile_lifecycle_gateway_rejects_all_ordinary_mutations_while_account_is_deletion_gated(): void
    {
        $activeProfile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Gated Active Profile',
            'is_active' => false,
        ]);
        $this->account->setAttribute('account_profile_deletion_gate', [
            'attempt_id' => 'u07a-lifecycle-gate',
            'attempt_generation' => 1,
        ]);
        $this->account->save();

        $service = app(AccountProfileManagementService::class);
        $operations = [
            fn (): mixed => $service->create([
                'account_id' => (string) $this->account->getKey(),
                'profile_type' => 'personal',
                'display_name' => 'Must Not Create',
            ], 'u07a-gated-create-'.uniqid('', true)),
            fn (): mixed => $service->delete(
                $activeProfile,
                'u07a-gated-delete-'.uniqid('', true),
            ),
            fn (): mixed => $service->forceDelete(
                $activeProfile,
                'u07a-gated-force-delete-'.uniqid('', true),
            ),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('A gated Account Profile mutation must conflict.');
            } catch (ConcurrencyConflictException) {
                // The Account gate owns this Profile until its deletion attempt completes.
            }
        }

        $activeProfile->delete();
        try {
            $service->restore(
                $activeProfile,
                'u07a-gated-restore-'.uniqid('', true),
            );
            $this->fail('A gated Account Profile mutation must conflict.');
        } catch (ConcurrencyConflictException) {
            // The Account gate also blocks restoration of a captured target.
        }

        $this->assertNotNull(AccountProfile::onlyTrashed()->find((string) $activeProfile->getKey()));
    }

    public function test_profile_delete_persists_and_dispatches_a_tombstone_outbox_event(): void
    {
        $commandId = 'u07a-profile-delete-'.uniqid('', true);
        $createResponse = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Outbox Delete Venue',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
            ],
            $this->getHeaders(),
        );

        $createResponse->assertCreated();
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $profile = AccountProfile::query()->findOrFail($profileId);
        $profile->is_active = false;
        $profile->save();
        $this->assertNotNull(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->first(),
        );

        $response = $this->deleteJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [],
            [...$this->getHeaders(), 'X-Request-Id' => $commandId],
        );

        $response->assertOk();
        $outbox = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);

        $this->assertNotNull($outbox);
        $this->assertSame('tombstone', $outbox['operation'] ?? null);
        $this->assertSame('completed', $outbox['delivery_state'] ?? null);
        $deletedProfile = AccountProfile::onlyTrashed()->find($profileId);
        $this->assertNotNull($deletedProfile);
        $this->assertSame(
            (int) ($outbox['aggregate_revision'] ?? -1),
            (int) $deletedProfile->aggregate_revision,
        );
        $this->assertNull(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->first(),
        );
    }

    public function test_profile_restore_persists_and_dispatches_an_upsert_outbox_event(): void
    {
        $deleteCommandId = 'u07a-profile-delete-before-restore-'.uniqid('', true);
        $restoreCommandId = 'u07a-profile-restore-'.uniqid('', true);
        $createResponse = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Outbox Restore Venue',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
            ],
            $this->getHeaders(),
        );

        $createResponse->assertCreated();
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $profile = AccountProfile::query()->findOrFail($profileId);
        $profile->is_active = false;
        $profile->save();

        $this->deleteJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [],
            [...$this->getHeaders(), 'X-Request-Id' => $deleteCommandId],
        )->assertOk();

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}/restore",
            [],
            [...$this->getHeaders(), 'X-Request-Id' => $restoreCommandId],
        );

        $response->assertOk();
        $outbox = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $restoreCommandId]);

        $this->assertNotNull($outbox);
        $this->assertSame('upsert', $outbox['operation'] ?? null);
        $this->assertSame('completed', $outbox['delivery_state'] ?? null);
        $this->assertNotNull(AccountProfile::query()->find($profileId));
        $poi = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->first();
        $this->assertNotNull($poi);
        $this->assertFalse((bool) $poi->is_active);
    }

    public function test_profile_force_delete_persists_and_dispatches_a_tombstone_outbox_event(): void
    {
        $deleteCommandId = 'u07a-profile-delete-before-force-'.uniqid('', true);
        $commandId = 'u07a-profile-force-delete-'.uniqid('', true);
        $createResponse = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Outbox Force Delete Venue',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
            ],
            $this->getHeaders(),
        );

        $createResponse->assertCreated();
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $profile = AccountProfile::query()->findOrFail($profileId);
        $profile->is_active = false;
        $profile->save();
        $this->assertNotNull(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->first(),
        );

        $this->deleteJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [],
            [...$this->getHeaders(), 'X-Request-Id' => $deleteCommandId],
        )->assertOk();
        $deleteReceipt = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_command_receipts')
            ->findOne(['_id' => $deleteCommandId]);
        $this->assertNotNull($deleteReceipt);

        Account::query()->findOrFail((string) $profile->account_id)->delete();

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}/force_delete",
            [],
            [...$this->getHeaders(), 'X-Request-Id' => $commandId],
        );

        $response->assertOk();
        $receipt = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_command_receipts')
            ->findOne(['_id' => $commandId]);
        $this->assertNotNull($receipt);
        $this->assertSame($deleteReceipt['outbox_event_id'] ?? null, $receipt['outbox_event_id'] ?? null);

        $outbox = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_outbox')
            ->findOne(['_id' => $receipt['outbox_event_id']]);
        $this->assertNotNull($outbox);
        $this->assertSame('tombstone', $outbox['operation'] ?? null);
        $this->assertSame('completed', $outbox['delivery_state'] ?? null);
        $this->assertNull(AccountProfile::withTrashed()->find($profileId));
        $this->assertNull(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->first(),
        );
    }

    public function test_profile_outbox_dispatcher_applies_map_poi_snapshot_once_with_a_checkpoint(): void
    {
        MapPoi::query()->delete();

        $profiles = app(AccountProfileManagementService::class);
        $profile = $profiles->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Outbox Map Poi Source',
            'location' => [
                'lat' => -20.0,
                'lng' => -40.0,
            ],
        ]);
        MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', (string) $profile->_id)
            ->delete();
        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_projection_checkpoints')
            ->deleteOne(['_id' => 'map_poi:'.(string) $profile->_id]);

        $commandId = 'u07a-map-poi-dispatch-'.uniqid('', true);
        $updated = $profiles->update(
            $profile,
            ['display_name' => 'Outbox Map Poi Applied'],
            commandId: $commandId,
            dispatchOutboxImmediately: false,
        );

        $database = DB::connection('tenant')->getDatabase();
        $outbox = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);
        $this->assertNotNull($outbox);
        $this->assertSame(
            0,
            $database
                ->selectCollection('account_profile_projection_checkpoints')
                ->countDocuments(['_id' => 'map_poi:'.(string) $profile->_id]),
        );

        app(AccountProfileOutboxDispatcher::class)->dispatchEvent((string) $outbox['_id']);

        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', (string) $profile->_id)
            ->first();
        $checkpoint = $database
            ->selectCollection('account_profile_projection_checkpoints')
            ->findOne(['_id' => 'map_poi:'.(string) $profile->_id]);

        $this->assertNotNull($projection);
        $this->assertSame($updated->display_name, $projection?->name);
        $this->assertNotNull($checkpoint);
        $this->assertSame((int) ($outbox['aggregate_revision'] ?? 0), (int) ($checkpoint['aggregate_revision'] ?? -1));

        app(AccountProfileOutboxDispatcher::class)->dispatchEvent((string) $outbox['_id']);

        $this->assertSame(
            1,
            $database
                ->selectCollection('account_profile_projection_checkpoints')
                ->countDocuments(['_id' => 'map_poi:'.(string) $profile->_id]),
        );
    }

    public function test_map_poi_outbox_effect_and_checkpoint_roll_back_together(): void
    {
        MapPoi::query()->delete();

        $profiles = app(AccountProfileManagementService::class);
        $profile = $profiles->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Outbox Atomic Map Poi Source',
            'location' => [
                'lat' => -20.0,
                'lng' => -40.0,
            ],
        ]);
        MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', (string) $profile->_id)
            ->delete();
        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_projection_checkpoints')
            ->deleteOne(['_id' => 'map_poi:'.(string) $profile->_id]);

        $commandId = 'u07a-map-poi-rollback-'.uniqid('', true);
        $profiles->update(
            $profile,
            ['display_name' => 'Outbox Atomic Map Poi Applied'],
            commandId: $commandId,
            dispatchOutboxImmediately: false,
        );
        $database = DB::connection('tenant')->getDatabase();
        $outbox = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);
        $this->assertNotNull($outbox);

        try {
            app(AccountProfileTransactionRunner::class)->run(
                function (AccountProfileTransactionContext $context) use ($outbox): void {
                    app(AccountProfileMapPoiOutboxConsumer::class)->consume(
                        $context,
                        [
                            ...$outbox->getArrayCopy(),
                            'projection' => $outbox['projection']->getArrayCopy(),
                        ],
                    );

                    throw new RuntimeException('forced outbox effect rollback');
                },
            );
            $this->fail('The test transaction should have been rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced outbox effect rollback', $exception->getMessage());
        }

        $this->assertNull(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', (string) $profile->_id)
                ->first(),
        );
        $this->assertSame(
            0,
            $database
                ->selectCollection('account_profile_projection_checkpoints')
                ->countDocuments(['_id' => 'map_poi:'.(string) $profile->_id]),
        );
    }

    public function test_profile_outbox_dispatcher_does_not_regress_map_poi_for_out_of_order_events(): void
    {
        MapPoi::query()->delete();

        $profiles = app(AccountProfileManagementService::class);
        $profile = $profiles->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Outbox Ordering Source',
            'location' => [
                'lat' => -20.0,
                'lng' => -40.0,
            ],
        ]);
        $profileId = (string) $profile->_id;
        $database = DB::connection('tenant')->getDatabase();
        MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->delete();
        $database
            ->selectCollection('account_profile_projection_checkpoints')
            ->deleteOne(['_id' => 'map_poi:'.$profileId]);

        $firstCommandId = 'u07a-map-poi-order-first-'.uniqid('', true);
        $first = $profiles->update(
            $profile,
            ['display_name' => 'Outbox Ordering First'],
            commandId: $firstCommandId,
            dispatchOutboxImmediately: false,
        );
        $secondCommandId = 'u07a-map-poi-order-second-'.uniqid('', true);
        $second = $profiles->update(
            $first,
            ['display_name' => 'Outbox Ordering Second'],
            commandId: $secondCommandId,
            dispatchOutboxImmediately: false,
        );

        $firstEvent = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $firstCommandId]);
        $secondEvent = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $secondCommandId]);
        $this->assertNotNull($firstEvent);
        $this->assertNotNull($secondEvent);
        $this->assertGreaterThan(
            (int) ($firstEvent['aggregate_revision'] ?? 0),
            (int) ($secondEvent['aggregate_revision'] ?? 0),
        );

        $dispatcher = app(AccountProfileOutboxDispatcher::class);
        $dispatcher->dispatchEvent((string) $secondEvent['_id']);
        $dispatcher->dispatchEvent((string) $firstEvent['_id']);

        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->first();
        $checkpoint = $database
            ->selectCollection('account_profile_projection_checkpoints')
            ->findOne(['_id' => 'map_poi:'.$profileId]);

        $this->assertNotNull($projection);
        $this->assertSame($second->display_name, $projection?->name);
        $this->assertNotNull($checkpoint);
        $this->assertSame(
            (int) ($secondEvent['aggregate_revision'] ?? 0),
            (int) ($checkpoint['aggregate_revision'] ?? -1),
        );
        $this->assertSame(
            'completed',
            (string) ($database
                ->selectCollection('account_profile_outbox')
                ->findOne(['_id' => $firstEvent['_id']])['delivery_state'] ?? ''),
        );
    }

    public function test_profile_outbox_dispatcher_releases_a_failed_event_for_retry(): void
    {
        MapPoi::query()->delete();

        $profiles = app(AccountProfileManagementService::class);
        $profile = $profiles->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Outbox Retry Source',
            'location' => [
                'lat' => -20.0,
                'lng' => -40.0,
            ],
        ]);
        $profileId = (string) $profile->_id;
        $database = DB::connection('tenant')->getDatabase();
        MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->delete();
        $database
            ->selectCollection('account_profile_projection_checkpoints')
            ->deleteOne(['_id' => 'map_poi:'.$profileId]);

        $commandId = 'u07a-map-poi-retry-'.uniqid('', true);
        $profiles->update(
            $profile,
            ['display_name' => 'Outbox Retry Applied'],
            commandId: $commandId,
            dispatchOutboxImmediately: false,
        );
        $outbox = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);
        $this->assertNotNull($outbox);
        $originalProjection = $outbox['projection'];
        $database
            ->selectCollection('account_profile_outbox')
            ->updateOne(['_id' => $outbox['_id']], ['$set' => ['projection' => null]]);

        try {
            app(AccountProfileOutboxDispatcher::class)->dispatchEvent((string) $outbox['_id']);
            $this->fail('The malformed event must be released for retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Account Profile Map POI upsert event requires an immutable projection.',
                $exception->getMessage(),
            );
        }

        $released = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['_id' => $outbox['_id']]);
        $this->assertNotNull($released);
        $this->assertSame('pending', (string) ($released['delivery_state'] ?? ''));
        $this->assertSame(1, (int) ($released['delivery_attempts'] ?? 0));
        $this->assertStringContainsString(
            'Account Profile Map POI upsert event requires an immutable projection.',
            (string) ($released['last_delivery_error'] ?? ''),
        );
        $this->assertSame(
            0,
            $database
                ->selectCollection('account_profile_projection_checkpoints')
                ->countDocuments(['_id' => 'map_poi:'.$profileId]),
        );

        $database
            ->selectCollection('account_profile_outbox')
            ->updateOne(['_id' => $outbox['_id']], ['$set' => ['projection' => $originalProjection]]);
        app(AccountProfileOutboxDispatcher::class)->dispatchEvent((string) $outbox['_id']);

        $completed = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['_id' => $outbox['_id']]);
        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->first();
        $this->assertNotNull($completed);
        $this->assertSame('completed', (string) ($completed['delivery_state'] ?? ''));
        $this->assertSame(2, (int) ($completed['delivery_attempts'] ?? 0));
        $this->assertNotNull($projection);
        $this->assertSame('Outbox Retry Applied', $projection?->name);
    }

    public function test_profile_update_dispatches_its_map_poi_outbox_event_after_commit(): void
    {
        MapPoi::query()->delete();

        $profile = app(AccountProfileManagementService::class)->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Outbox Dispatch HTTP Source',
            'location' => [
                'lat' => -20.0,
                'lng' => -40.0,
            ],
        ]);
        MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', (string) $profile->_id)
            ->delete();

        $commandId = 'u07a-map-poi-http-'.uniqid('', true);
        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profile->_id}",
            ['display_name' => 'Outbox Dispatch HTTP Applied'],
            [...$this->getHeaders(), 'X-Request-Id' => $commandId],
        );

        $response->assertOk();
        $database = DB::connection('tenant')->getDatabase();
        $outbox = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);
        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', (string) $profile->_id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame('completed', (string) ($outbox['delivery_state'] ?? ''));
        $this->assertNotNull($projection);
        $this->assertSame('Outbox Dispatch HTTP Applied', $projection?->name);
        $this->assertSame(
            1,
            $database
                ->selectCollection('account_profile_projection_checkpoints')
                ->countDocuments(['_id' => 'map_poi:'.(string) $profile->_id]),
        );
    }

    public function test_profile_outbox_dispatcher_replays_pending_events(): void
    {
        MapPoi::query()->delete();

        $profiles = app(AccountProfileManagementService::class);
        $profile = $profiles->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Outbox Pending Source',
            'location' => [
                'lat' => -20.0,
                'lng' => -40.0,
            ],
        ]);
        MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', (string) $profile->_id)
            ->delete();

        $commandId = 'u07a-outbox-pending-'.uniqid('', true);
        $profiles->update(
            $profile,
            ['display_name' => 'Outbox Pending Applied'],
            commandId: $commandId,
            dispatchOutboxImmediately: false,
        );

        $database = DB::connection('tenant')->getDatabase();
        $pending = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);
        $this->assertSame('pending', (string) ($pending['delivery_state'] ?? ''));
        $this->assertSame(0, (int) ($pending['delivery_attempts'] ?? -1));

        $this->assertGreaterThanOrEqual(
            1,
            app(AccountProfileOutboxDispatcher::class)->dispatchAvailable(),
        );

        $outbox = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);
        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', (string) $profile->_id)
            ->first();

        $this->assertSame('completed', (string) ($outbox['delivery_state'] ?? ''));
        $this->assertSame('Outbox Pending Applied', $projection?->name);
    }

    public function test_account_onboarding_persists_and_dispatches_its_profile_outbox_event(): void
    {
        $this->markTestSkipped(
            'Deferred to foundation_documentation/todos/active/v0.4.1/TODO-v0.4.1-account-profile-gallery-outbox-durability.md during the Tuesday, July 21, 2026 v0.4.0 promotion replay.'
        );

        MapPoi::query()->delete();

        $commandId = 'u07a-profile-create-'.uniqid('', true);
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Outbox Direct Create',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
            ],
            [...$this->getHeaders(), 'X-Request-Id' => $commandId],
        );

        $response->assertCreated();
        $profileId = (string) $response->json('data.account_profile.id');
        $database = DB::connection('tenant')->getDatabase();
        $outbox = $database
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);

        $this->assertNotNull($outbox);
        $this->assertSame(1, (int) ($outbox['aggregate_revision'] ?? 0));
        $this->assertSame('completed', (string) ($outbox['delivery_state'] ?? ''));
        $this->assertNotNull(
            MapPoi::query()
                ->where('ref_type', 'account_profile')
                ->where('ref_id', $profileId)
                ->first(),
        );
        $this->assertSame(
            1,
            $database
                ->selectCollection('account_profile_projection_checkpoints')
                ->countDocuments(['_id' => 'map_poi:'.$profileId]),
        );
    }

    public function test_account_onboarding_replays_the_same_request_id_without_a_second_account_or_outbox_event(): void
    {
        $this->markTestSkipped(
            'Deferred to foundation_documentation/todos/active/v0.4.1/TODO-v0.4.1-account-profile-gallery-outbox-durability.md during the Tuesday, July 21, 2026 v0.4.0 promotion replay.'
        );

        $commandId = 'u07a-profile-onboarding-replay-'.uniqid('', true);
        $payload = [
            'name' => 'Outbox Onboarding Replay',
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'venue',
            'location' => [
                'lat' => -20.0,
                'lng' => -40.0,
            ],
        ];
        $headers = [...$this->getHeaders(), 'X-Request-Id' => $commandId];

        $first = $this->postJson("{$this->base_tenant_api_admin}account_onboardings", $payload, $headers);
        $second = $this->postJson("{$this->base_tenant_api_admin}account_onboardings", $payload, $headers);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('data.account.id'), $second->json('data.account.id'));
        $this->assertSame($first->json('data.account_profile.id'), $second->json('data.account_profile.id'));
        $this->assertSame(
            1,
            DB::connection('tenant')
                ->getDatabase()
                ->selectCollection('account_profile_outbox')
                ->countDocuments(['command_id' => $commandId]),
        );
    }

    public function test_public_account_profile_index_forbids_landlord_user_without_tenant_access(): void
    {
        $noAccessUser = LandlordUser::query()->create([
            'name' => 'No Access User',
            'emails' => [strtolower('no-access-'.uniqid('', true).'@example.org')],
            'password' => 'Secret!234',
        ]);

        Sanctum::actingAs($noAccessUser, []);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(403);
    }

    public function test_public_account_profile_index_allows_landlord_user_with_tenant_access(): void
    {
        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, []);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
    }

    public function test_public_account_profile_index_filters_by_profile_type(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile Personal',
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Account Secondary',
            'document' => 'DOC-SECONDARY',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Profile Venue',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?filter[profile_type]=venue"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertTrue($items->every(fn (array $item): bool => $item['profile_type'] === 'venue'));
    }

    public function test_public_account_profile_index_returns_only_favoritable_types(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Private Profile',
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Favoritable Account',
            'document' => 'DOC-FAVORITABLE',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Public Venue',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('venue', $items->first()['profile_type'] ?? null);
    }

    public function test_public_account_profile_index_excludes_publicly_discoverable_non_favoritable_types(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'internal_partner',
            'label' => 'Internal Partner',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_favoritable' => false,
            ],
        ]);

        $discoverableAccount = Account::create([
            'name' => 'Discoverable Venue',
            'document' => 'DOC-DISCOVERABLE-VENUE',
        ]);
        AccountProfile::create([
            'account_id' => (string) $discoverableAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Visible Venue',
            'is_active' => true,
        ]);

        $internalAccount = Account::create([
            'name' => 'Internal Partner Account',
            'document' => 'DOC-INTERNAL-PARTNER',
        ]);
        AccountProfile::create([
            'account_id' => (string) $internalAccount->_id,
            'profile_type' => 'internal_partner',
            'display_name' => 'Internal Partner Profile',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertSame(['venue'], $items->pluck('profile_type')->unique()->values()->all());
        $this->assertFalse(
            $items->contains(fn (array $item): bool => ($item['profile_type'] ?? null) === 'internal_partner')
        );
    }

    public function test_public_account_profile_index_excludes_personal_profiles_even_when_inviteable_and_favoritable(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::query()
            ->where('type', 'personal')
            ->update([
                'capabilities.is_favoritable' => true,
                'capabilities.is_inviteable' => true,
                'capabilities.is_publicly_discoverable' => false,
            ]);

        $personal = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Personal Inviteable Profile',
            'slug' => 'personal-inviteable-profile',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        TenantProfileType::create([
            'type' => 'public_catalog_guard',
            'label' => 'Public Catalog Guard',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
            ],
        ]);

        $secondary = Account::create([
            'name' => 'Public Catalog Account',
            'document' => 'DOC-PUBLIC-CATALOG-GUARD',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'public_catalog_guard',
            'display_name' => 'Public Catalog Guard',
            'slug' => 'public-catalog-guard',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $indexResponse = $this->getJson("{$this->base_api_tenant}account_profiles");

        $indexResponse->assertStatus(200);
        $this->assertSame(
            ['Public Catalog Guard'],
            collect($indexResponse->json('data'))->pluck('display_name')->values()->all()
        );

        $filteredResponse = $this->getJson(
            "{$this->base_api_tenant}account_profiles?profile_type=personal"
        );

        $filteredResponse->assertStatus(200);
        $this->assertSame([], $filteredResponse->json('data'));

        $detailResponse = $this->getJson(
            "{$this->base_api_tenant}account_profiles/{$personal->slug}"
        );

        $detailResponse->assertStatus(404);
    }

    public function test_public_account_profile_detail_rejects_a_navigable_non_favoritable_type(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'navigable_non_favoritable',
            'label' => 'Navigable Non Favoritable',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_favoritable' => false,
            ],
        ]);

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'navigable_non_favoritable',
            'display_name' => 'Navigable But Not Public',
            'slug' => 'navigable-but-not-public',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $this->getJson("{$this->base_api_tenant}account_profiles/{$profile->slug}")
            ->assertStatus(404);
    }

    public function test_public_account_profile_index_rejects_filter_bypass_for_non_favoritable_type(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'internal_partner',
            'label' => 'Internal Partner',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_favoritable' => false,
            ],
        ]);

        $internalAccount = Account::create([
            'name' => 'Internal Filter Account',
            'document' => 'DOC-INTERNAL-FILTER',
        ]);
        AccountProfile::create([
            'account_id' => (string) $internalAccount->_id,
            'profile_type' => 'internal_partner',
            'display_name' => 'Internal Filter Profile',
            'slug' => 'internal-filter-profile',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?filter[profile_type]=internal_partner"
        );

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_public_account_profile_index_excludes_unbackfilled_types_missing_required_public_capabilities(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'public_catalog_fixture',
            'label' => 'Public Catalog Fixture',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $secondary = Account::create([
            'name' => 'Legacy Public Catalog Account',
            'document' => 'DOC-LEGACY-PUBLIC-CATALOG',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'public_catalog_fixture',
            'display_name' => 'Legacy Public Catalog Profile',
            'slug' => 'legacy-public-catalog-profile',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_public_account_profile_index_returns_empty_when_filter_requests_non_favoritable_type(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Personal Profile',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?filter[profile_type]=personal"
        );

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_public_account_profile_index_filters_by_taxonomy_terms_on_backend(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Italian Venue',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
            'taxonomy_terms_flat' => ['cuisine:italian'],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $secondary = Account::create([
            'name' => 'Japanese Account',
            'document' => 'DOC-JAPANESE-FILTER',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Japanese Venue',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'japanese'],
            ],
            'taxonomy_terms_flat' => ['cuisine:japanese'],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?taxonomy[0][type]=cuisine&taxonomy[0][value]=italian"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Italian Venue', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_index_returns_runtime_facets_for_the_full_filtered_universe_before_pagination(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'artist_public',
            'label' => 'Artist Public',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => false,
                'has_events' => false,
            ],
        ]);
        TaxonomyTerm::create([
            'taxonomy_id' => (string) Taxonomy::query()->where('slug', 'cuisine')->firstOrFail()->_id,
            'slug' => 'japanese',
            'name' => 'Japanese',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Paginated Venue',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
            'taxonomy_terms_flat' => ['cuisine:italian'],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $secondary = Account::create([
            'name' => 'Facet Artist Account',
            'document' => 'DOC-FACET-ARTIST',
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'artist_public',
            'display_name' => 'Paginated Artist',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'japanese'],
            ],
            'taxonomy_terms_flat' => ['cuisine:japanese'],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles?page=1&per_page=1");

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.display_name', 'Paginated Artist');
        $response->assertJsonPath(
            'discovery_filter_facets.surface',
            'discovery.account_profiles',
        );
        $response->assertJsonPath(
            'discovery_filter_catalog.surface',
            'discovery.account_profiles',
        );

        $filterKeys = $response->json('discovery_filter_facets.filter_keys') ?? [];
        $this->assertContains('artist_public', $filterKeys);
        $this->assertContains('venue', $filterKeys);
        $catalogFilterKeys = collect($response->json('discovery_filter_catalog.filters') ?? [])
            ->pluck('key')
            ->values()
            ->all();
        $this->assertContains('artist_public', $catalogFilterKeys);
        $this->assertContains('venue', $catalogFilterKeys);

        $cuisineTerms = collect($response->json('discovery_filter_facets.taxonomy_options.cuisine.terms') ?? [])
            ->pluck('value')
            ->values()
            ->all();
        $this->assertSame(['italian', 'japanese'], $cuisineTerms);
        $catalogCuisineTerms = collect($response->json('discovery_filter_catalog.taxonomy_options.cuisine.terms') ?? [])
            ->pluck('value')
            ->values()
            ->all();
        $this->assertSame(['italian', 'japanese'], $catalogCuisineTerms);
    }

    public function test_public_account_profile_index_runtime_catalog_hides_empty_and_non_discoverable_types(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'visible_runtime_type',
            'label' => 'Visible Runtime Type',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => false,
                'has_events' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'empty_runtime_type',
            'label' => 'Empty Runtime Type',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => false,
                'has_events' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'hidden_runtime_type',
            'label' => 'Hidden Runtime Type',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => false,
                'is_poi_enabled' => false,
                'has_events' => false,
            ],
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'visible_runtime_type',
            'display_name' => 'Visible Runtime Profile',
            'taxonomy_terms' => [],
            'taxonomy_terms_flat' => [],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $secondary = Account::create([
            'name' => 'Hidden Runtime Account',
            'document' => 'DOC-HIDDEN-RUNTIME',
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'hidden_runtime_type',
            'display_name' => 'Hidden Runtime Profile',
            'taxonomy_terms' => [],
            'taxonomy_terms_flat' => [],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles?page=1&per_page=1");

        $response->assertStatus(200);

        $filterKeys = $response->json('discovery_filter_facets.filter_keys') ?? [];
        $this->assertContains('visible_runtime_type', $filterKeys);
        $this->assertNotContains('empty_runtime_type', $filterKeys);
        $this->assertNotContains('hidden_runtime_type', $filterKeys);

        $catalogFilterKeys = collect($response->json('discovery_filter_catalog.filters') ?? [])
            ->pluck('key')
            ->values()
            ->all();
        $this->assertContains('visible_runtime_type', $catalogFilterKeys);
        $this->assertNotContains('empty_runtime_type', $catalogFilterKeys);
        $this->assertNotContains('hidden_runtime_type', $catalogFilterKeys);
    }

    public function test_public_account_profile_index_runtime_facets_are_self_excluding_for_selected_filters(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'artist_public',
            'label' => 'Artist Public',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => false,
                'has_events' => false,
            ],
        ]);
        TaxonomyTerm::create([
            'taxonomy_id' => (string) Taxonomy::query()->where('slug', 'cuisine')->firstOrFail()->_id,
            'slug' => 'japanese',
            'name' => 'Japanese',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Italian Venue',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
            'taxonomy_terms_flat' => ['cuisine:italian'],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $secondary = Account::create([
            'name' => 'Facet Venue Account',
            'document' => 'DOC-FACET-VENUE-TWO',
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Japanese Venue',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'japanese'],
            ],
            'taxonomy_terms_flat' => ['cuisine:japanese'],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $artistAccount = Account::create([
            'name' => 'Facet Artist Account',
            'document' => 'DOC-FACET-ARTIST-TWO',
        ]);
        AccountProfile::create([
            'account_id' => (string) $artistAccount->_id,
            'profile_type' => 'artist_public',
            'display_name' => 'Italian Artist',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
            'taxonomy_terms_flat' => ['cuisine:italian'],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $aggregateCalls = [];
        EventBus::listen(
            'account_profiles.public_discovery_aggregate',
            static function (string $purpose, array $pipeline) use (&$aggregateCalls): void {
                $aggregateCalls[] = [
                    'purpose' => $purpose,
                    'pipeline' => $pipeline,
                ];
            }
        );

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?profile_type=venue&taxonomy[0][type]=cuisine&taxonomy[0][value]=italian"
        );

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.display_name', 'Italian Venue');

        $filterKeys = $response->json('discovery_filter_facets.filter_keys') ?? [];
        $this->assertContains(
            'artist_public',
            $filterKeys,
            'Type facets must exclude only the active type selection, not collapse to the selected type.'
        );
        $catalogFilterKeys = collect($response->json('discovery_filter_catalog.filters') ?? [])
            ->pluck('key')
            ->values()
            ->all();
        $this->assertContains(
            'artist_public',
            $catalogFilterKeys,
            'Canonical discovery catalog must preserve the backend-pruned compatible type universe.'
        );

        $cuisineTerms = collect($response->json('discovery_filter_facets.taxonomy_options.cuisine.terms') ?? [])
            ->pluck('value')
            ->values()
            ->all();
        $this->assertSame(['italian', 'japanese'], $cuisineTerms);
        $catalogCuisineTerms = collect($response->json('discovery_filter_catalog.taxonomy_options.cuisine.terms') ?? [])
            ->pluck('value')
            ->values()
            ->all();
        $this->assertSame(['italian', 'japanese'], $catalogCuisineTerms);

        $this->assertCount(1, $aggregateCalls);
        $this->assertSame(
            'public_discovery_page_with_runtime_facets',
            $aggregateCalls[0]['purpose'],
        );
        $this->assertTrue(
            collect($aggregateCalls[0]['pipeline'])->contains(
                static fn (array $stage): bool => array_key_exists('$facet', $stage)
            ),
            'Public discovery runtime facets must be computed through a single $facet aggregate.'
        );
    }

    public function test_public_account_profile_index_taxonomy_filters_use_flat_index_projection(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Flat Italian Venue',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
            'taxonomy_terms_flat' => ['cuisine:italian'],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $secondary = Account::create([
            'name' => 'Legacy Italian Account',
            'document' => 'DOC-LEGACY-FLAT-FILTER',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Legacy Italian Without Flat',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $indexNames = collect(DB::connection('tenant')->getCollection('account_profiles')->listIndexes())
            ->map(static fn ($index): string => (string) ($index['name'] ?? ''))
            ->all();

        $this->assertContains(
            'idx_account_profiles_public_taxonomy_flat_v1',
            $indexNames,
            'Public taxonomy filtering must be backed by the flat taxonomy index.'
        );

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?taxonomy[0][type]=cuisine&taxonomy[0][value]=italian"
        );

        $response->assertStatus(200);
        $this->assertSame(
            ['Flat Italian Venue'],
            collect($response->json('data'))->pluck('display_name')->values()->all()
        );
    }

    public function test_public_account_profile_index_rejects_unbounded_taxonomy_filters(): void
    {
        $this->createAccountUser([]);

        $query = [];
        for ($index = 0; $index < InputConstraints::DISCOVERY_FILTER_PUBLIC_TAXONOMY_FILTERS_MAX + 1; $index++) {
            $query["taxonomy[{$index}][type]"] = 'cuisine';
            $query["taxonomy[{$index}][value]"] = "term-{$index}";
        }

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?".http_build_query($query)
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['taxonomy']);
    }

    public function test_public_account_profile_index_rejects_unbounded_search(): void
    {
        $this->createAccountUser([]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?search=".str_repeat('a', InputConstraints::NAME_MAX + 1)
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['search']);
    }

    public function test_public_account_profile_index_rejects_unbounded_profile_type_list(): void
    {
        $this->createAccountUser([]);

        $query = [
            'profile_type' => array_map(
                static fn (int $index): string => "type-{$index}",
                range(1, InputConstraints::DISCOVERY_FILTER_TYPE_OPTIONS_MAX + 1)
            ),
        ];

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?".http_build_query($query)
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_type']);
    }

    public function test_public_account_profile_index_returns_empty_when_top_level_profile_type_is_non_favoritable(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Personal Profile',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?profile_type=personal"
        );

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_public_account_profile_show_by_slug_returns_public_active_profile(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Slug Detail Venue',
            'slug' => 'slug-detail-venue',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/slug-detail-venue"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', 'slug-detail-venue');
        $response->assertJsonPath('data.display_name', 'Slug Detail Venue');
        $response->assertJsonPath('data.can_open_public_detail', true);
        $response->assertJsonPath('data.public_detail_path', '/parceiro/slug-detail-venue');
    }

    public function test_admin_account_profile_show_does_not_advertise_public_detail_for_private_profile(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Private Formatter Venue',
            'slug' => 'private-formatter-venue',
            'is_active' => true,
            'visibility' => 'private',
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            $this->getHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('data.can_open_public_detail', false);
        $response->assertJsonPath('data.public_detail_path', null);
    }

    public function test_public_account_profile_show_by_slug_projects_effective_mirrored_contact_channels(): void
    {
        $this->enableContactChannelsCapability('venue');

        $source = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Contact Source Venue',
            'slug' => 'contact-source-venue',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();

        $sourceResponse = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $source->_id,
            [
                'contact_mode' => 'own',
                'contact_channels' => [
                    [
                        'draft_key' => 'email-source',
                        'type' => 'email',
                        'value' => 'contato@source.example',
                        'title' => 'E-mail principal',
                    ],
                    [
                        'draft_key' => 'whatsapp-source',
                        'type' => 'whatsapp',
                        'value' => '+55 (27) 99999-1111',
                        'title' => 'WhatsApp principal',
                        'metadata' => [
                            'initial_messages' => [
                                [
                                    'id' => 'cta-ola',
                                    'cta' => 'Olá',
                                    'mensagem' => 'Olá! Gostaria de saber mais.',
                                ],
                            ],
                        ],
                    ],
                ],
                'contact_bubble_channel_draft_key' => 'whatsapp-source',
            ],
            $this->getHeaders()
        );

        $sourceResponse->assertOk();
        $sourceBubbleChannelId = $sourceResponse->json('data.contact_bubble_channel_id');
        $this->assertIsString($sourceBubbleChannelId);

        $mirrorAccount = Account::create([
            'name' => 'Mirror Account',
            'document' => 'DOC-MIRROR-'.uniqid('', true),
        ]);
        $mirror = AccountProfile::create([
            'account_id' => (string) $mirrorAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Mirrored Contact Venue',
            'slug' => 'mirrored-contact-venue',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();

        $mirrorResponse = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $mirror->_id,
            [
                'contact_mode' => 'mirrored_account_profile',
                'contact_source_account_profile_id' => (string) $source->_id,
                'contact_bubble_channel_id' => $sourceBubbleChannelId,
            ],
            $this->getHeaders()
        );

        $mirrorResponse->assertOk();
        $mirrorResponse->assertJsonPath('data.contact_mode', 'mirrored_account_profile');
        $mirrorResponse->assertJsonPath('data.contact_source_account_profile.id', (string) $source->_id);
        $mirrorResponse->assertJsonPath('data.effective_contact_source.id', (string) $source->_id);
        $mirrorResponse->assertJsonPath('data.contact_channels', []);
        $mirrorResponse->assertJsonPath('data.effective_contact_channels.0.type', 'email');
        $mirrorResponse->assertJsonPath('data.effective_contact_channels.1.type', 'whatsapp');
        $mirrorResponse->assertJsonPath('data.effective_contact_bubble_channel.id', $sourceBubbleChannelId);

        $this->createAccountUser([]);

        $publicResponse = $this->getJson(
            "{$this->base_api_tenant}account_profiles/mirrored-contact-venue"
        );

        $publicResponse->assertOk();
        $publicResponse->assertJsonPath('data.contact_mode', 'mirrored_account_profile');
        $publicResponse->assertJsonPath('data.effective_contact_source.id', (string) $source->_id);
        $publicResponse->assertJsonPath('data.effective_contact_channels.0.type', 'email');
        $publicResponse->assertJsonPath('data.effective_contact_channels.1.type', 'whatsapp');
        $publicResponse->assertJsonPath('data.effective_contact_bubble_channel.id', $sourceBubbleChannelId);
        $publicResponse->assertJsonMissing([
            'data.contact_source_account_profile',
            'data.contact_source_account_profile_id',
            'data.contact_channels',
            'data.contact_bubble_channel_id',
        ]);
    }

    public function test_contact_mirror_source_deactivation_fails_closed_for_reads_and_new_writes(): void
    {
        $this->enableContactChannelsCapability('venue');
        $source = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Active Contact Source',
            'slug' => 'active-contact-source',
            'contact_mode' => 'own',
            'is_active' => true,
        ])->fresh();
        $sourceWrite = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $source->_id,
            [
                'contact_channels' => [[
                    'draft_key' => 'active-source-whatsapp',
                    'type' => 'whatsapp',
                    'value' => '+55 (27) 99999-1111',
                ]],
                'contact_bubble_channel_draft_key' => 'active-source-whatsapp',
            ],
            $this->getHeaders(),
        );
        $sourceWrite->assertOk();
        $sourceBubbleChannelId = $sourceWrite->json('data.contact_bubble_channel_id');
        $this->assertIsString($sourceBubbleChannelId);

        $mirrorAccount = Account::create([
            'name' => 'Deactivated Source Mirror Account',
            'document' => 'DOC-DEACTIVATED-SOURCE-'.uniqid('', true),
        ]);
        $mirror = AccountProfile::create([
            'account_id' => (string) $mirrorAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Deactivated Source Mirror',
            'slug' => 'deactivated-source-mirror',
            'is_active' => true,
        ])->fresh();
        $mirrorWrite = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $mirror->_id,
            [
                'contact_mode' => 'mirrored_account_profile',
                'contact_source_account_profile_id' => (string) $source->_id,
                'contact_bubble_channel_id' => $sourceBubbleChannelId,
            ],
            $this->getHeaders(),
        );
        $mirrorWrite->assertOk();

        $source->is_active = false;
        $source->save();

        $readAfterDeactivation = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $mirror->_id,
            $this->getHeaders(),
        );
        $readAfterDeactivation->assertOk();
        $readAfterDeactivation->assertJsonPath('data.effective_contact_source', null);
        $readAfterDeactivation->assertJsonPath('data.effective_contact_channels', []);
        $readAfterDeactivation->assertJsonPath('data.effective_contact_bubble_channel', null);

        $writeAfterDeactivation = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $mirror->_id,
            [
                'contact_mode' => 'mirrored_account_profile',
                'contact_source_account_profile_id' => (string) $source->_id,
            ],
            $this->getHeaders(),
        );
        $writeAfterDeactivation->assertStatus(422);
        $writeAfterDeactivation->assertJsonValidationErrors([
            'contact_source_account_profile_id',
        ]);
    }

    public function test_contact_channel_overlapping_stale_snapshots_preserve_valid_bubble_state(): void
    {
        $this->enableContactChannelsCapability('venue');
        $burstLevel = max(2, (int) (getenv('DELPHI_BCI_BURST_LEVEL') ?: 2));
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Concurrent Contact Profile',
            'slug' => 'concurrent-contact-profile',
            'contact_mode' => 'own',
            'is_active' => true,
        ])->fresh();

        $initialWrite = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'contact_channels' => [[
                    'draft_key' => 'initial-whatsapp',
                    'type' => 'whatsapp',
                    'value' => '+55 (27) 99999-0101',
                    'title' => 'Inicial',
                ]],
                'contact_bubble_channel_draft_key' => 'initial-whatsapp',
            ],
            $this->getHeaders(),
        );
        $initialWrite->assertOk();
        $initialChannelId = $initialWrite->json('data.contact_bubble_channel_id');
        $this->assertIsString($initialChannelId);

        $staleUnrelatedSnapshots = [];
        for ($index = 0; $index < $burstLevel; $index++) {
            $staleUnrelatedSnapshots[] = AccountProfile::query()
                ->findOrFail((string) $profile->_id);
        }

        $clearWrite = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'contact_channels' => [],
                'contact_bubble_channel_id' => null,
            ],
            $this->getHeaders(),
        );
        $clearWrite->assertOk();

        $service = $this->app->make(AccountProfileManagementService::class);
        foreach ($staleUnrelatedSnapshots as $index => $staleSnapshot) {
            $service->update(
                $staleSnapshot,
                ['display_name' => "Unrelated stale update {$index}"],
                dispatchOutboxImmediately: false,
            );
        }

        $afterUnrelatedWrites = AccountProfile::query()
            ->findOrFail((string) $profile->_id);
        $afterUnrelatedRead = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            $this->getHeaders(),
        );
        $afterUnrelatedRead->assertOk();
        $afterUnrelatedRead->assertJsonPath('data.contact_channels', []);
        $afterUnrelatedRead->assertJsonPath('data.contact_bubble_channel_id', null);
        $this->assertSame(
            'Unrelated stale update '.($burstLevel - 1),
            $afterUnrelatedWrites->display_name,
        );

        $restoredWrite = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'contact_channels' => [[
                    'draft_key' => 'competing-whatsapp',
                    'type' => 'whatsapp',
                    'value' => '+55 (27) 99999-0202',
                    'title' => 'Base concorrente',
                ]],
                'contact_bubble_channel_draft_key' => 'competing-whatsapp',
            ],
            $this->getHeaders(),
        );
        $restoredWrite->assertOk();
        $competingChannelId = $restoredWrite->json('data.contact_bubble_channel_id');
        $this->assertIsString($competingChannelId);

        $staleContactSnapshots = [];
        for ($index = 0; $index < $burstLevel; $index++) {
            $staleContactSnapshots[] = AccountProfile::query()
                ->findOrFail((string) $profile->_id);
        }

        foreach ($staleContactSnapshots as $index => $staleSnapshot) {
            $service->update(
                $staleSnapshot,
                [
                    'contact_channels' => [[
                        'id' => $competingChannelId,
                        'type' => 'whatsapp',
                        'value' => '+55 (27) 99999-0202',
                        'title' => "Concorrente {$index}",
                    ]],
                    'contact_bubble_channel_id' => $competingChannelId,
                ],
                dispatchOutboxImmediately: false,
            );
        }

        $finalRead = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            $this->getHeaders(),
        );
        $finalRead->assertOk();
        $finalRead->assertJsonPath('data.contact_channels.0.id', $competingChannelId);
        $finalRead->assertJsonPath(
            'data.contact_channels.0.title',
            'Concorrente '.($burstLevel - 1),
        );
        $finalRead->assertJsonPath('data.contact_bubble_channel_id', $competingChannelId);
        $finalRead->assertJsonCount(1, 'data.contact_channels');
        $this->assertNotSame($initialChannelId, $competingChannelId);
    }

    public function test_public_contact_projection_omits_raw_contact_state_when_capability_is_revoked(): void
    {
        $this->enableContactChannelsCapability('venue');
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Revoked Contact Capability',
            'slug' => 'revoked-contact-capability',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();
        $write = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'contact_channels' => [[
                    'draft_key' => 'revoked-whatsapp',
                    'type' => 'whatsapp',
                    'value' => '+55 (27) 99999-1111',
                ]],
                'contact_bubble_channel_draft_key' => 'revoked-whatsapp',
            ],
            $this->getHeaders(),
        );
        $write->assertOk();

        $profileType = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $profileType->capabilities = array_merge(
            is_array($profileType->capabilities ?? null) ? $profileType->capabilities : [],
            ['has_contact_channels' => false],
        );
        $profileType->save();

        $publicRead = $this->getJson(
            "{$this->base_api_tenant}account_profiles/revoked-contact-capability",
        );
        $publicRead->assertOk();
        $publicRead->assertJsonPath('data.effective_contact_channels', []);
        $publicRead->assertJsonPath('data.effective_contact_bubble_channel', null);
        $publicRead->assertJsonMissing([
            'contact_channels',
            'contact_bubble_channel_id',
            'contact_source_account_profile_id',
            'contact_source_account_profile',
        ]);
    }

    public function test_public_account_profile_index_excludes_non_queryable_type_even_if_discoverable_flag_is_true(): void
    {
        $this->createAccountUser([]);

        $venueType = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $venueType->capabilities = [
            'is_queryable' => false,
            'is_publicly_discoverable' => true,
            'is_publicly_navigable' => true,
            'is_favoritable' => true,
            'is_poi_enabled' => true,
            'has_events' => true,
            'has_nested_profile_groups' => true,
        ];
        $venueType->save();

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Non Queryable Venue',
            'slug' => 'non-queryable-venue',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_public_account_profile_show_by_slug_uses_canonical_public_catalog_eligibility(): void
    {
        $this->createAccountUser([]);

        $venueType = TenantProfileType::query()->where('type', 'venue')->firstOrFail();
        $venueType->capabilities = [
            'is_queryable' => true,
            'is_publicly_discoverable' => true,
            'is_publicly_navigable' => false,
            'is_favoritable' => true,
            'is_poi_enabled' => true,
            'has_events' => true,
            'has_nested_profile_groups' => true,
        ];
        $venueType->save();

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Route Disabled Venue',
            'slug' => 'route-disabled-venue',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $index = $this->getJson("{$this->base_api_tenant}account_profiles");
        $index->assertStatus(200);
        $index->assertJsonPath('data.0.slug', 'route-disabled-venue');
        $index->assertJsonPath('data.0.can_open_public_detail', true);
        $index->assertJsonPath('data.0.public_detail_path', '/parceiro/route-disabled-venue');

        $detail = $this->getJson(
            "{$this->base_api_tenant}account_profiles/route-disabled-venue"
        );

        $detail->assertStatus(200);
    }

    public function test_public_account_profile_show_by_slug_includes_agenda_occurrences_for_future_venue_occurrences(): void
    {
        Queue::fake();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, []);

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Agenda Detail Venue',
            'slug' => 'agenda-detail-venue',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $futureEvent = $this->createAgendaEventForAccountProfile(
            $profile,
            title: 'Future Venue Event',
            startsAt: Carbon::now()->addDay(),
            endsAt: Carbon::now()->addDay()->addHours(2),
        );
        $eventCoverUrl = 'https://example.org/account-profile-agenda-event-cover.jpg';
        $venueCoverUrl = 'https://example.org/account-profile-agenda-venue-cover.jpg';
        $futureEvent->forceFill([
            'thumb' => [
                'type' => 'image',
                'data' => [
                    'url' => $eventCoverUrl,
                ],
            ],
            'venue' => [
                'id' => (string) $profile->_id,
                'display_name' => $profile->display_name,
                'tagline' => 'Tag',
                'hero_image_url' => 'https://example.org/account-profile-agenda-venue-hero.jpg',
                'cover_url' => $venueCoverUrl,
                'logo_url' => null,
                'taxonomy_terms' => [],
            ],
        ])->save();
        $firstFutureOccurrence = EventOccurrence::query()
            ->where('event_id', (string) $futureEvent->_id)
            ->firstOrFail();
        $firstFutureOccurrence->forceFill([
            'thumb' => null,
            'venue' => [
                'id' => (string) $profile->_id,
                'display_name' => $profile->display_name,
                'tagline' => 'Tag',
                'hero_image_url' => 'https://example.org/account-profile-agenda-venue-hero.jpg',
                'cover_url' => $venueCoverUrl,
                'logo_url' => null,
                'taxonomy_terms' => [],
            ],
        ])->save();
        $secondFutureOccurrence = $firstFutureOccurrence->replicate();
        $secondFutureOccurrence->occurrence_slug = 'future-venue-event-occ-2';
        $secondFutureOccurrence->starts_at = Carbon::now()->addDays(2);
        $secondFutureOccurrence->ends_at = Carbon::now()->addDays(2)->addHours(2);
        $secondFutureOccurrence->effective_ends_at = Carbon::now()->addDays(2)->addHours(2);
        $secondFutureOccurrence->unset('occurrence_index');
        $secondFutureOccurrence->save();
        $futureEvent->occurrence_refs = [
            [
                'occurrence_id' => (string) $firstFutureOccurrence->_id,
                'occurrence_slug' => (string) $firstFutureOccurrence->occurrence_slug,
                'order' => 0,
            ],
            [
                'occurrence_id' => (string) $secondFutureOccurrence->_id,
                'occurrence_slug' => (string) $secondFutureOccurrence->occurrence_slug,
                'order' => 1,
            ],
        ];
        $futureEvent->save();
        $this->createAgendaEventForAccountProfile(
            $profile,
            title: 'Past Venue Event',
            startsAt: Carbon::now()->subDays(2),
            endsAt: Carbon::now()->subDays(2)->addHours(2),
        );

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/agenda-detail-venue"
        );

        $response->assertStatus(200);
        $occurrences = $this->app->make(AccountProfileAgendaOccurrencesService::class)->forProfile($profile);

        $this->assertCount(2, $occurrences);
        $this->assertSame((string) $futureEvent->_id, $occurrences[0]['event_id'] ?? null);
        $this->assertSame((string) $futureEvent->_id, $occurrences[1]['event_id'] ?? null);
        $this->assertNotSame($occurrences[0]['occurrence_id'] ?? null, $occurrences[1]['occurrence_id'] ?? null);
        $response->assertJsonCount(2, 'data.agenda_occurrences');
        $response->assertJsonPath('data.agenda_occurrences.0.event_id', (string) $futureEvent->_id);
        $response->assertJsonPath('data.agenda_occurrences.0.title', 'Future Venue Event');
        $response->assertJsonPath('data.agenda_occurrences.0.thumb.data.url', $eventCoverUrl);
        $response->assertJsonPath('data.agenda_occurrences.0.hero_image_url', $eventCoverUrl);
        $response->assertJsonPath('data.agenda_occurrences.0.venue.cover_url', $venueCoverUrl);
        $response->assertJsonPath('data.agenda_occurrences.1.event_id', (string) $futureEvent->_id);
        $response->assertJsonPath('data.agenda_occurrences.1.title', 'Future Venue Event');
        $firstAgendaItem = $response->json('data.agenda_occurrences.0');
        $this->assertIsArray($firstAgendaItem);
        $this->assertArrayNotHasKey('occurrences', $firstAgendaItem);
        $this->assertArrayNotHasKey('event_parties', $firstAgendaItem);
        $this->assertArrayNotHasKey('profile_groups', $firstAgendaItem);
        $this->assertArrayNotHasKey('programming_items', $firstAgendaItem);
        $this->assertArrayNotHasKey('capabilities', $firstAgendaItem);
        $this->assertArrayNotHasKey('created_by', $firstAgendaItem);
        $this->assertNotSame(
            $response->json('data.agenda_occurrences.0.venue.cover_url'),
            $response->json('data.agenda_occurrences.0.hero_image_url')
        );
    }

    public function test_public_account_profile_show_by_slug_caps_agenda_occurrences_to_public_page_size(): void
    {
        Queue::fake();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, []);

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Capped Agenda Venue',
            'slug' => 'capped-agenda-venue',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $event = $this->createAgendaEventForAccountProfile(
            $profile,
            title: 'Capped Venue Event',
            startsAt: Carbon::now()->addDay(),
            endsAt: Carbon::now()->addDay()->addHours(2),
        );
        $firstOccurrence = EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->firstOrFail();

        for ($index = 2; $index <= InputConstraints::PUBLIC_PAGE_SIZE_MAX + 1; $index++) {
            $occurrence = $firstOccurrence->replicate();
            $occurrence->occurrence_slug = "capped-venue-event-occ-{$index}";
            $occurrence->starts_at = Carbon::now()->addDays($index + 1);
            $occurrence->ends_at = Carbon::now()->addDays($index + 1)->addHours(2);
            $occurrence->effective_ends_at = Carbon::now()->addDays($index + 1)->addHours(2);
            $occurrence->unset('occurrence_index');
            $occurrence->save();
        }

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/capped-agenda-venue"
        );

        $response->assertStatus(200);
        $response->assertJsonCount(InputConstraints::PUBLIC_PAGE_SIZE_MAX, 'data.agenda_occurrences');
    }

    public function test_public_account_profile_show_by_slug_includes_agenda_occurrences_for_future_artist_occurrences(): void
    {
        Queue::fake();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, []);

        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => false,
                'has_events' => true,
            ],
        ]);

        $artistAccount = Account::create([
            'name' => 'Artist Account',
            'document' => 'DOC-ARTIST-AGENDA',
        ]);

        $profile = AccountProfile::create([
            'account_id' => (string) $artistAccount->_id,
            'profile_type' => 'artist',
            'display_name' => 'Ananda Torres Agenda',
            'slug' => 'ananda-torres-agenda',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $futureEvent = $this->createAgendaEventForAccountProfile(
            $profile,
            title: 'Future Artist Event',
            startsAt: Carbon::now()->addHours(5),
            endsAt: Carbon::now()->addHours(7),
            viaLinkedParticipation: true,
        );

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/ananda-torres-agenda"
        );

        $response->assertStatus(200);
        $occurrences = $response->json('data.agenda_occurrences', []);
        $this->assertCount(1, $occurrences);
        $this->assertSame((string) $futureEvent->_id, $occurrences[0]['event_id'] ?? null);
        $this->assertSame('Future Artist Event', $occurrences[0]['title'] ?? null);
    }

    public function test_public_account_profile_show_by_slug_includes_agenda_occurrences_for_capability_enabled_poi_profile_via_linked_event_parties(): void
    {
        Queue::fake();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, []);

        TenantProfileType::create([
            'type' => 'community_hub',
            'label' => 'Community Hub',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
                'has_events' => true,
            ],
        ]);

        $profileAccount = Account::create([
            'name' => 'Community Hub Account',
            'document' => 'DOC-COMMUNITY-HUB-AGENDA',
        ]);

        $profile = AccountProfile::create([
            'account_id' => (string) $profileAccount->_id,
            'profile_type' => 'community_hub',
            'display_name' => 'Community Hub Agenda',
            'slug' => 'community-hub-agenda',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $futureEvent = $this->createAgendaEventForAccountProfile(
            $profile,
            title: 'Community Hub Linked Event',
            startsAt: Carbon::now()->addHours(8),
            endsAt: Carbon::now()->addHours(11),
            viaLinkedParticipation: true,
        );

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/community-hub-agenda"
        );

        $response->assertStatus(200);
        $occurrences = $response->json('data.agenda_occurrences', []);
        $this->assertCount(1, $occurrences);
        $this->assertSame((string) $futureEvent->_id, $occurrences[0]['event_id'] ?? null);
        $this->assertSame('Community Hub Linked Event', $occurrences[0]['title'] ?? null);
    }

    public function test_public_account_profile_show_by_slug_excludes_agenda_occurrences_for_profile_without_events_capability(): void
    {
        Queue::fake();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, []);

        TenantProfileType::create([
            'type' => 'poi_without_events',
            'label' => 'POI Without Events',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
                'has_events' => false,
            ],
        ]);

        $profileAccount = Account::create([
            'name' => 'POI Without Events Account',
            'document' => 'DOC-POI-WITHOUT-EVENTS',
        ]);

        $profile = AccountProfile::create([
            'account_id' => (string) $profileAccount->_id,
            'profile_type' => 'poi_without_events',
            'display_name' => 'POI Without Events',
            'slug' => 'poi-without-events',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $this->createAgendaEventForAccountProfile(
            $profile,
            title: 'Profile Without Agenda Capability Event',
            startsAt: Carbon::now()->addHours(4),
            endsAt: Carbon::now()->addHours(6),
        );

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/poi-without-events"
        );

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.agenda_occurrences', []));
    }

    public function test_public_account_profile_show_by_slug_uses_materialized_effective_end_for_open_occurrences(): void
    {
        Queue::fake();

        $landlordUser = LandlordUser::query()->firstOrFail();
        Sanctum::actingAs($landlordUser, []);

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Timed Agenda Venue',
            'slug' => 'timed-agenda-venue',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $liveEvent = $this->createAgendaEventForAccountProfile(
            $profile,
            title: 'Open Live Venue Event',
            startsAt: Carbon::now()->subHours(2),
            endsAt: null,
        );
        $expiredEvent = $this->createAgendaEventForAccountProfile(
            $profile,
            title: 'Expired Open Venue Event',
            startsAt: Carbon::now()->subHours(4),
            endsAt: null,
        );

        $liveOccurrence = EventOccurrence::query()
            ->where('event_id', (string) $liveEvent->_id)
            ->first();
        $this->assertNotNull($liveOccurrence?->effective_ends_at);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/timed-agenda-venue"
        );

        $response->assertStatus(200);
        $agendaOccurrences = $response->json('data.agenda_occurrences', []);
        $this->assertCount(1, $agendaOccurrences);
        $this->assertSame((string) $liveEvent->_id, $agendaOccurrences[0]['event_id'] ?? null);
        $this->assertNotContains(
            (string) $expiredEvent->_id,
            array_map(
                static fn (array $occurrence): ?string => $occurrence['event_id'] ?? null,
                $agendaOccurrences,
            ),
        );
    }

    public function test_public_account_profile_show_by_slug_returns_not_found_for_private_profile(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Private Detail Venue',
            'slug' => 'private-detail-venue',
            'is_active' => true,
            'visibility' => 'friends_only',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/private-detail-venue"
        );

        $response->assertStatus(404);
    }

    private function createAgendaEventForAccountProfile(
        AccountProfile $profile,
        string $title,
        Carbon $startsAt,
        ?Carbon $endsAt = null,
        bool $viaLinkedParticipation = false,
    ): Event {
        $eventParties = $viaLinkedParticipation
            ? [[
                'party_type' => $profile->profile_type,
                'party_ref_id' => (string) $profile->_id,
                'metadata' => [
                    'display_name' => $profile->display_name,
                    'slug' => $profile->slug,
                    'profile_type' => $profile->profile_type,
                    'avatar_url' => null,
                    'cover_url' => null,
                    'taxonomy_terms' => [],
                ],
                'permissions' => [
                    'can_view' => true,
                    'can_edit' => false,
                ],
            ]]
            : [[
                'party_type' => 'personal',
                'party_ref_id' => 'personal-1',
                'metadata' => [
                    'display_name' => 'Performer One',
                    'slug' => 'performer-one',
                    'profile_type' => 'personal',
                    'avatar_url' => null,
                    'cover_url' => null,
                    'taxonomy_terms' => [],
                ],
                'permissions' => [
                    'can_view' => true,
                    'can_edit' => false,
                ],
            ]];

        $event = Event::create([
            'title' => $title,
            'content' => 'Agenda event content',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0, -20.0],
                ],
            ],
            'place_ref' => $viaLinkedParticipation
                ? null
                : [
                    'type' => 'account_profile',
                    'id' => (string) $profile->_id,
                    'metadata' => [
                        'display_name' => $profile->display_name,
                    ],
                ],
            'type' => [
                'id' => 'type-1',
                'name' => 'Show',
                'slug' => 'show',
                'description' => 'Show desc',
                'icon' => null,
                'color' => null,
            ],
            'venue' => $viaLinkedParticipation
                ? null
                : [
                    'id' => (string) $profile->_id,
                    'display_name' => $profile->display_name,
                    'tagline' => 'Tag',
                    'hero_image_url' => null,
                    'logo_url' => null,
                    'taxonomy_terms' => [],
                ],
            'geo_location' => [
                'type' => 'Point',
                'coordinates' => [-40.0, -20.0],
            ],
            'thumb' => [
                'type' => 'image',
                'data' => [
                    'url' => 'https://example.org/thumb.jpg',
                ],
            ],
            'date_time_start' => $startsAt,
            'date_time_end' => $endsAt,
            'event_parties' => $eventParties,
            'tags' => ['music'],
            'categories' => ['culture'],
            'taxonomy_terms' => [],
            'publication' => [
                'status' => 'published',
                'publish_at' => Carbon::now()->subMinute(),
            ],
            'is_active' => true,
        ]);

        app(EventOccurrenceSyncService::class)->syncFromEvent($event, [[
            'date_time_start' => Carbon::instance($startsAt),
            'date_time_end' => $endsAt !== null ? Carbon::instance($endsAt) : null,
        ]]);

        $this->makeCanonicalTenantCurrent(allowSingleTenantContext: true);

        return $event;
    }

    public function test_public_account_profile_near_returns_distance_sorted_favoritable_profiles_only(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'artist',
            'label' => 'Artist',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => false,
            ],
        ]);
        TenantProfileType::create([
            'type' => 'blocked-poi',
            'label' => 'Blocked Poi',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => false,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        $secondary = Account::create([
            'name' => 'Geo Secondary',
            'document' => 'DOC-GEO-SECONDARY',
        ]);

        $tertiary = Account::create([
            'name' => 'Geo Tertiary',
            'document' => 'DOC-GEO-TERTIARY',
        ]);
        $blocked = Account::create([
            'name' => 'Geo Blocked',
            'document' => 'DOC-GEO-BLOCKED',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Near Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0002, -20.0002],
            ],
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Far Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0120, -20.0120],
            ],
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $tertiary->_id,
            'profile_type' => 'artist',
            'display_name' => 'Non Poi Artist',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0001, -20.0001],
            ],
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $blocked->_id,
            'profile_type' => 'blocked-poi',
            'display_name' => 'Blocked Poi',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0003, -20.0003],
            ],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('page', 1);
        $response->assertJsonPath('page_size', 10);
        $response->assertJsonPath('has_more', false);

        $items = collect($response->json('data'));
        $this->assertCount(2, $items);
        $this->assertTrue(
            $items->every(static fn (array $item): bool => ($item['profile_type'] ?? null) === 'venue')
        );
        $this->assertSame(
            ['Near Venue', 'Far Venue'],
            $items->pluck('display_name')->values()->all()
        );
        $this->assertFalse(
            $items->contains(static fn (array $item): bool => ($item['display_name'] ?? null) === 'Blocked Poi')
        );
        $this->assertNotNull($items->first()['distance_meters'] ?? null);
        $this->assertIsNumeric($items->first()['distance_meters'] ?? null);
        $this->assertLessThan(
            (float) ($items->last()['distance_meters'] ?? INF),
            (float) ($items->first()['distance_meters'] ?? 0)
        );
    }

    public function test_public_account_profile_near_filters_by_taxonomy_terms_on_backend(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nearby Italian Venue',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'italian'],
            ],
            'taxonomy_terms_flat' => ['cuisine:italian'],
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0002, -20.0002],
            ],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $secondary = Account::create([
            'name' => 'Nearby Japanese Account',
            'document' => 'DOC-NEAR-JAPANESE-FILTER',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nearby Japanese Venue',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'japanese'],
            ],
            'taxonomy_terms_flat' => ['cuisine:japanese'],
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0003, -20.0003],
            ],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10&taxonomy[0][type]=cuisine&taxonomy[0][value]=italian"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Nearby Italian Venue', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_near_accepts_multiple_profile_types(): void
    {
        $this->createAccountUser([]);

        TenantProfileType::create([
            'type' => 'restaurant',
            'label' => 'Restaurant',
            'allowed_taxonomies' => ['cuisine'],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_favoritable' => true,
                'is_publicly_discoverable' => true,
                'is_poi_enabled' => true,
            ],
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Typed Venue',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0002, -20.0002],
            ],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $secondary = Account::create([
            'name' => 'Typed Restaurant Account',
            'document' => 'DOC-NEAR-TYPES-FILTER',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'restaurant',
            'display_name' => 'Typed Restaurant',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0003, -20.0003],
            ],
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10&profile_type[]=venue&profile_type[]=restaurant"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(2, $items);
        $this->assertSame(
            ['venue', 'restaurant'],
            $items->pluck('profile_type')->values()->all()
        );
    }

    public function test_public_account_profile_near_requires_origin_coordinates(): void
    {
        $this->createAccountUser([]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&page=1&page_size=10"
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.origin_lng'));
    }

    public function test_public_account_profile_near_rejects_unbounded_type_filter_work(): void
    {
        $this->createAccountUser([]);
        $profileTypes = array_map(
            static fn (int $index): string => "type-{$index}",
            range(1, InputConstraints::DISCOVERY_FILTER_TYPE_OPTIONS_MAX + 1)
        );
        $query = http_build_query([
            'origin_lat' => -20.0,
            'origin_lng' => -40.0,
            'page' => 1,
            'page_size' => 10,
            'profile_type' => $profileTypes,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles/near?{$query}");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_type']);
    }

    public function test_public_account_profile_near_rejects_unknown_filter_keys_and_unbounded_radius(): void
    {
        $this->createAccountUser([]);
        $query = http_build_query([
            'origin_lat' => -20.0,
            'origin_lng' => -40.0,
            'max_distance_meters' => InputConstraints::PUBLIC_GEO_DISTANCE_MAX_METERS + 1,
            'filter' => [
                'profile_type' => ['venue'],
                'unexpected' => ['value'],
            ],
            'page' => 1,
            'page_size' => 10,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles/near?{$query}");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['max_distance_meters', 'filter']);
    }

    public function test_public_account_profile_near_excludes_private_visibility_profiles(): void
    {
        $this->createAccountUser([]);

        $publicProfile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Public Nearby',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0005, -20.0005],
            ],
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Nearby Private Account',
            'document' => 'DOC-NEARBY-PRIVATE',
        ]);
        $privateProfile = AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Private Nearby',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0007, -20.0007],
            ],
            'is_active' => true,
        ]);

        AccountProfile::query()
            ->where('_id', (string) $publicProfile->_id)
            ->update(['visibility' => 'public']);
        AccountProfile::query()
            ->where('_id', (string) $privateProfile->_id)
            ->update(['visibility' => 'friends_only']);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Public Nearby', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_index_excludes_legacy_profiles_without_visibility_field(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Explicit Public Venue',
            'is_active' => true,
            'visibility' => 'public',
        ]);

        $legacyAccount = Account::create([
            'name' => 'Legacy Visibility Account',
            'document' => 'DOC-LEGACY-VISIBILITY-INDEX',
        ]);

        AccountProfile::raw(static function ($collection) use ($legacyAccount): void {
            $collection->insertOne([
                '_id' => new ObjectId,
                'account_id' => (string) $legacyAccount->_id,
                'profile_type' => 'venue',
                'display_name' => 'Legacy Missing Visibility',
                'slug' => 'legacy-missing-visibility-index',
                'is_active' => true,
                'location' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0008, -20.0008],
                ],
                'taxonomy_terms' => [],
            ]);
        });

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Explicit Public Venue', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_near_excludes_legacy_profiles_without_visibility_field(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Explicit Public Nearby',
            'is_active' => true,
            'visibility' => 'public',
            'location' => [
                'type' => 'Point',
                'coordinates' => [-40.0005, -20.0005],
            ],
        ]);

        $legacyAccount = Account::create([
            'name' => 'Legacy Near Visibility Account',
            'document' => 'DOC-LEGACY-VISIBILITY-NEAR',
        ]);

        AccountProfile::raw(static function ($collection) use ($legacyAccount): void {
            $collection->insertOne([
                '_id' => new ObjectId,
                'account_id' => (string) $legacyAccount->_id,
                'profile_type' => 'venue',
                'display_name' => 'Legacy Missing Visibility Nearby',
                'slug' => 'legacy-missing-visibility-near',
                'is_active' => true,
                'location' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0006, -20.0006],
                ],
                'taxonomy_terms' => [],
            ]);
        });

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&origin_lng=-40.0&page=1&page_size=10"
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Explicit Public Nearby', $items->first()['display_name'] ?? null);
    }

    public function test_account_profile_model_defaults_visibility_to_public(): void
    {
        $this->createAccountUser([]);

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Default Visibility Venue',
            'is_active' => true,
        ]);

        $stored = AccountProfile::query()
            ->where('_id', (string) $profile->_id)
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame('public', $stored?->visibility);
    }

    public function test_public_account_profile_index_excludes_inactive_profiles(): void
    {
        $this->createAccountUser([]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Active Venue',
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Inactive Account',
            'document' => 'DOC-INACTIVE',
        ]);

        AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Inactive Venue',
            'is_active' => false,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Active Venue', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_index_excludes_private_visibility_profiles(): void
    {
        $this->createAccountUser([]);

        $publicProfile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Public Venue',
            'is_active' => true,
        ]);

        $secondary = Account::create([
            'name' => 'Private Visibility Account',
            'document' => 'DOC-PRIVATE-VISIBILITY',
        ]);

        $privateProfile = AccountProfile::create([
            'account_id' => (string) $secondary->_id,
            'profile_type' => 'venue',
            'display_name' => 'Private Venue',
            'is_active' => true,
        ]);

        AccountProfile::query()
            ->where('_id', (string) $publicProfile->_id)
            ->update(['visibility' => 'public']);
        AccountProfile::query()
            ->where('_id', (string) $privateProfile->_id)
            ->update(['visibility' => 'friends_only']);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Public Venue', $items->first()['display_name'] ?? null);
    }

    public function test_public_account_profile_index_returns_empty_when_none(): void
    {
        $this->createAccountUser([]);

        AccountProfile::query()->delete();

        $response = $this->getJson("{$this->base_api_tenant}account_profiles");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_public_account_profile_index_accepts_page_size_alias(): void
    {
        $this->createAccountUser([]);

        $secondAccount = Account::create([
            'name' => 'Second Account',
            'document' => 'DOC-PAGE-SIZE-2',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Page Size 1',
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Page Size 2',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles?page_size=1");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, (int) $response->json('per_page'));
    }

    public function test_public_account_profile_index_rejects_page_size_above_safe_maximum(): void
    {
        $this->createAccountUser([]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?page_size=".(InputConstraints::PUBLIC_PAGE_SIZE_MAX + 1)
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page_size']);
    }

    public function test_public_account_profile_index_rejects_page_above_safe_depth(): void
    {
        $this->createAccountUser([]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles?page=".(InputConstraints::PUBLIC_PAGE_MAX + 1).'&page_size=10'
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page']);
    }

    public function test_public_account_profile_near_rejects_page_above_safe_depth(): void
    {
        $this->createAccountUser([]);

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/near?origin_lat=-20.0&origin_lng=-40.0&page=".(InputConstraints::PUBLIC_PAGE_MAX + 1).'&page_size=10'
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page']);
    }

    public function test_public_account_profile_query_service_clamps_page_size_when_called_directly(): void
    {
        $paginator = app(AccountProfileQueryService::class)->publicPaginate(
            [],
            InputConstraints::PUBLIC_PAGE_SIZE_MAX + 100
        );

        $this->assertSame(InputConstraints::PUBLIC_PAGE_SIZE_MAX, $paginator->perPage());
    }

    public function test_public_account_profile_query_service_clamps_page_depth_when_called_directly(): void
    {
        $paginator = app(AccountProfileQueryService::class)->publicPaginate(
            ['page' => InputConstraints::PUBLIC_PAGE_MAX + 100],
            1
        );

        $this->assertSame(InputConstraints::PUBLIC_PAGE_MAX, $paginator->currentPage());
    }

    public function test_public_account_profile_near_clamps_page_depth_when_called_directly(): void
    {
        $payload = app(AccountProfileQueryService::class)->publicNear([
            'page' => InputConstraints::PUBLIC_PAGE_MAX + 100,
            'page_size' => 1,
        ]);

        $this->assertSame(InputConstraints::PUBLIC_PAGE_MAX, $payload['page']);
    }

    public function test_public_account_profile_near_clamps_page_size_when_called_directly(): void
    {
        $payload = app(AccountProfileQueryService::class)->publicNear([
            'page_size' => InputConstraints::PUBLIC_PAGE_SIZE_MAX + 100,
        ]);

        $this->assertSame(InputConstraints::PUBLIC_PAGE_SIZE_MAX, $payload['page_size']);
    }

    public function test_public_account_profile_near_keeps_near_default_page_size_when_called_directly_with_invalid_size(): void
    {
        $payload = app(AccountProfileQueryService::class)->publicNear([
            'page_size' => 0,
        ]);

        $this->assertSame(10, $payload['page_size']);
    }

    public function test_public_account_profile_index_supports_search_param(): void
    {
        $this->createAccountUser([]);

        $secondAccount = Account::create([
            'name' => 'Second Search Account',
            'document' => 'DOC-SEARCH-2',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Jazz House',
            'taxonomy_terms' => [
                ['type' => 'cuisine', 'value' => 'vegan'],
            ],
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $secondAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Classical Club',
            'is_active' => true,
        ]);

        $response = $this->getJson("{$this->base_api_tenant}account_profiles?search=vegan");

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertCount(1, $items);
        $this->assertSame('Jazz House', $items->first()['display_name'] ?? null);

        $partialResponse = $this->getJson("{$this->base_api_tenant}account_profiles?search=ega");
        $partialResponse->assertStatus(200);
        $partialItems = collect($partialResponse->json('data'));
        $this->assertCount(1, $partialItems);
        $this->assertSame('Jazz House', $partialItems->first()['display_name'] ?? null);
    }

    public function test_admin_account_profile_index_filters_by_ownership_state(): void
    {
        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Managed Profile',
            'is_active' => true,
        ]);

        $unmanagedAccount = Account::create([
            'name' => 'Unmanaged Account',
            'document' => 'DOC-UNMANAGED',
        ]);

        AccountProfile::create([
            'account_id' => (string) $unmanagedAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Unmanaged Profile',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles?ownership_state=unmanaged",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertTrue(
            $items->every(static fn (array $item): bool => ($item['ownership_state'] ?? null) === 'unmanaged')
        );
    }

    public function test_account_profile_types_returns_registry(): void
    {
        $response = $this->getJson("{$this->base_tenant_api_admin}account_profile_types", $this->getHeaders());

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_account_profile_types_forbidden_without_ability(): void
    {
        $user = LandlordUser::query()->firstOrFail();

        Sanctum::actingAs($user, ['account-users:create']);

        $response = $this->getJson("{$this->base_tenant_api_admin}account_profile_types");

        $response->assertStatus(403);
    }

    public function test_account_profile_create_requires_location_when_poi_enabled(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Test Venue Missing Location',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);

        $created = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Test Venue',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
            ],
            $this->getHeaders()
        );

        $created->assertStatus(201);
        $created->assertJsonPath('data.account_profile.profile_type', 'venue');
    }

    public function test_account_onboarding_projects_map_poi_with_type_visual_snapshot(): void
    {
        MapPoi::query()->delete();

        TenantProfileType::query()
            ->where('type', 'venue')
            ->update([
                'poi_visual' => [
                    'mode' => 'icon',
                    'icon' => 'restaurant',
                    'color' => '#EB2528',
                    'icon_color' => '#101010',
                ],
            ]);

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Venue Visual Projection',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.67134,
                    'lng' => -40.49540,
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $profileId = (string) $response->json('data.account_profile.id');
        $this->assertNotSame('', $profileId);

        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->first();

        $this->assertNotNull($projection);
        $this->assertSame('icon', data_get($projection->visual, 'mode'));
        $this->assertSame('restaurant', data_get($projection->visual, 'icon'));
        $this->assertSame('#EB2528', data_get($projection->visual, 'color'));
        $this->assertSame('#101010', data_get($projection->visual, 'icon_color'));
        $this->assertSame('type_definition', data_get($projection->visual, 'source'));
    }

    public function test_account_profile_create_stores_avatar_and_cover_uploads(): void
    {
        Storage::fake('public');

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Media',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            ],
        );

        $response->assertStatus(201);
        $avatarUrl = $response->json('data.account_profile.avatar_url');
        $coverUrl = $response->json('data.account_profile.cover_url');
        $this->assertNotEmpty($avatarUrl);
        $this->assertNotEmpty($coverUrl);

        $profileId = (string) $response->json('data.account_profile.id');
        $profile = AccountProfile::query()->findOrFail($profileId);
        $profile->profile_type = 'venue';
        $profile->visibility = 'public';
        $profile->is_active = true;
        $profile->save();

        $this->assertMediaUrlHealthy($avatarUrl);
        $this->assertMediaUrlHealthy($coverUrl);
        $this->assertMediaStored($profileId, 'avatar');
        $this->assertMediaStored($profileId, 'cover');
    }

    public function test_avatar_and_cover_media_are_not_publicly_served_when_profile_is_not_publicly_exposed(): void
    {
        Storage::fake('public');

        $response = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Media Gate',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            ],
        );

        $response->assertStatus(201);
        $profileId = (string) $response->json('data.account_profile.id');
        $avatarUrl = $response->json('data.account_profile.avatar_url');
        $coverUrl = $response->json('data.account_profile.cover_url');
        $this->assertNotEmpty($avatarUrl);
        $this->assertNotEmpty($coverUrl);

        $profile = AccountProfile::query()->findOrFail($profileId);
        $this->assertMediaUrlAccess($avatarUrl, 404);
        $this->assertMediaUrlAccess($coverUrl, 404);

        $profile->visibility = 'friends_only';
        $profile->save();
        $this->assertMediaUrlAccess($avatarUrl, 404);
        $this->assertMediaUrlAccess($coverUrl, 404);

        $profile->visibility = 'public';
        $profile->is_active = false;
        $profile->save();
        $this->assertMediaUrlAccess($avatarUrl, 404);
        $this->assertMediaUrlAccess($coverUrl, 404);

        $profile->is_active = true;
        $profile->save();
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'personal'],
            ['label' => 'Personal',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => false,
                    'is_favoritable' => true,
                    'is_publicly_discoverable' => false,
                    'is_poi_enabled' => false,
                    'has_events' => false,
                ],
            ],
        );
        $this->assertMediaUrlAccess($avatarUrl, 404);
        $this->assertMediaUrlAccess($coverUrl, 404);

        TenantProfileType::query()->updateOrCreate(
            ['type' => 'personal'],
            ['label' => 'Personal',
                'allowed_taxonomies' => [],
                'capabilities' => [
                    'is_queryable' => true,
                    'is_publicly_navigable' => true,
                    'is_favoritable' => true,
                    'is_publicly_discoverable' => false,
                    'is_poi_enabled' => false,
                    'has_events' => false,
                ],
            ],
        );
        $this->assertMediaUrlAccess($avatarUrl, 404);
        $this->assertMediaUrlAccess($coverUrl, 404);
    }

    public function test_account_profile_create_rejects_unknown_taxonomy(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Venue Taxonomy',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
                'taxonomy_terms' => [
                    ['type' => 'unknown', 'value' => 'value'],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_account_profile_create_rejects_disallowed_taxonomy(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Personal Taxonomy',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'taxonomy_terms' => [
                    ['type' => 'cuisine', 'value' => 'italian'],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_account_profile_create_accepts_allowed_taxonomy(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Venue Taxonomy',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
                'taxonomy_terms' => [
                    ['type' => 'cuisine', 'value' => 'italian'],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.account_profile.taxonomy_terms.0.type', 'cuisine');
        $response->assertJsonPath('data.account_profile.taxonomy_terms.0.value', 'italian');
        $response->assertJsonPath('data.account_profile.taxonomy_terms.0.name', 'Italian');
        $response->assertJsonPath('data.account_profile.taxonomy_terms.0.taxonomy_name', 'Cuisine');
        $response->assertJsonPath('data.account_profile.taxonomy_terms.0.label', 'Italian');
    }

    public function test_account_profile_update_replaces_avatar_upload(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Replace',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $originalAvatarUrl = $createResponse->json('data.account_profile.avatar_url');
        $this->assertNotEmpty($originalAvatarUrl);
        $originalPath = $this->assertMediaStored($profileId, 'avatar');

        $updateResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                '_method' => 'PATCH',
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 220, 220),
            ],
        );

        $updateResponse->assertStatus(200);
        $newAvatarUrl = $updateResponse->json('data.avatar_url');
        $this->assertNotEmpty($newAvatarUrl);

        $this->assertMediaUrlAccess($newAvatarUrl, 404);
        $this->assertMediaStored($profileId, 'avatar');
        if ($originalPath) {
            Storage::disk('public')->assertMissing($originalPath);
        }
    }

    public function test_account_profile_update_replaces_cover_upload(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Replace Cover',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'cover' => UploadedFile::fake()->image('cover.png', 1200, 600),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $originalCoverUrl = $createResponse->json('data.account_profile.cover_url');
        $this->assertNotEmpty($originalCoverUrl);
        $originalPath = $this->assertMediaStored($profileId, 'cover');

        $updateResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                '_method' => 'PATCH',
                'cover' => UploadedFile::fake()->image('cover.jpg', 1400, 700),
            ],
        );

        $updateResponse->assertStatus(200);
        $newCoverUrl = $updateResponse->json('data.cover_url');
        $this->assertNotEmpty($newCoverUrl);

        $this->assertMediaUrlAccess($newCoverUrl, 404);
        $this->assertMediaStored($profileId, 'cover');
        if ($originalPath) {
            Storage::disk('public')->assertMissing($originalPath);
        }
    }

    public function test_account_profile_update_media_uploads_refresh_map_poi_projection_urls(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'POI Media Refresh',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');

        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->first();

        $this->assertNotNull($projection);
        $this->assertNull($projection?->avatar_url);
        $this->assertNull($projection?->cover_url);

        $commandId = 'profile-media-update-outbox';
        $updateResponse = $this->withHeaders([
            ...$this->getMultipartHeaders(),
            'X-Request-Id' => $commandId,
        ])->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                '_method' => 'PATCH',
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 220, 220),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1400, 700),
            ],
        );

        $updateResponse->assertStatus(200);
        $avatarUrl = $updateResponse->json('data.avatar_url');
        $coverUrl = $updateResponse->json('data.cover_url');
        $this->assertNotEmpty($avatarUrl);
        $this->assertNotEmpty($coverUrl);
        $profile = AccountProfile::query()->findOrFail($profileId);

        $outboxEvent = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('account_profile_outbox')
            ->findOne(['command_id' => $commandId]);
        $this->assertNotNull($outboxEvent);
        $outboxPayload = $outboxEvent->getArrayCopy();
        $this->assertSame('completed', $outboxPayload['delivery_state'] ?? null);
        $this->assertSame($profile->avatar_url, $outboxPayload['projection']['avatar_url'] ?? null);
        $this->assertSame($profile->cover_url, $outboxPayload['projection']['cover_url'] ?? null);

        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->first();

        $this->assertNotNull($projection);
        $this->assertSame($profile->avatar_url, $projection?->avatar_url);
        $this->assertSame($profile->cover_url, $projection?->cover_url);
        $this->assertStringEndsWith((string) $projection?->avatar_url, (string) $avatarUrl);
        $this->assertStringEndsWith((string) $projection?->cover_url, (string) $coverUrl);
    }

    public function test_account_profile_media_update_rejects_reused_request_id_with_different_upload(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Media Request Id Guard',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $commandId = 'profile-media-request-id-'.uniqid('', true);
        $headers = [
            ...$this->getMultipartHeaders(),
            'X-Request-Id' => $commandId,
        ];
        $url = "{$this->base_tenant_api_admin}account_profiles/{$profileId}";

        $first = $this->withHeaders($headers)->post($url, [
            '_method' => 'PATCH',
            'avatar' => UploadedFile::fake()->image('first.png', 220, 220),
        ]);
        $second = $this->withHeaders($headers)->post($url, [
            '_method' => 'PATCH',
            'avatar' => UploadedFile::fake()->image('second.png', 320, 320),
        ]);

        $first->assertOk();
        $second->assertStatus(422)->assertJsonValidationErrors('X-Request-Id');
        $this->assertSame(
            1,
            DB::connection('tenant')
                ->getDatabase()
                ->selectCollection('account_profile_outbox')
                ->countDocuments(['command_id' => $commandId]),
        );
    }

    public function test_account_profile_media_update_restores_files_after_a_known_transaction_abort(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Media Abort Compensation',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'avatar' => UploadedFile::fake()->image('original.png', 220, 220),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $profile = AccountProfile::query()->findOrFail($profileId);
        $originalAvatarUrl = (string) $profile->avatar_url;
        $originalAvatarPath = $this->assertMediaStored($profileId, 'avatar');
        $originalAvatarContents = Storage::disk('public')->get($originalAvatarPath);
        $request = Request::create(
            "{$this->base_tenant_url}admin/api/v1/account_profiles/{$profileId}",
            'PATCH',
            [],
            [],
            ['avatar' => UploadedFile::fake()->image('replacement.png', 320, 320)],
        );
        $media = app(\App\Application\AccountProfiles\AccountProfileMediaService::class);
        $backup = $media->captureMutationBackup($request, $profile);
        $this->assertNotNull($backup);
        $commandId = 'profile-media-known-abort-'.uniqid('', true);

        try {
            app(AccountProfileManagementService::class)->update(
                $profile,
                [],
                commandId: $commandId,
                mutateWithinTransaction: function (AccountProfile $persistedProfile) use ($media, $request): void {
                    $media->applyUploads($request, $persistedProfile);

                    throw new RuntimeException('Injected known transaction abort.');
                },
                compensateKnownRollback: static fn (): mixed => $backup->restore(),
            );
            $this->fail('The injected transaction abort must be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected known transaction abort.', $exception->getMessage());
        }

        $profile = AccountProfile::query()->findOrFail($profileId);
        $this->assertSame($originalAvatarUrl, $profile->avatar_url);
        $this->assertSame($originalAvatarContents, Storage::disk('public')->get($originalAvatarPath));
        $this->assertSame(
            0,
            DB::connection('tenant')
                ->getDatabase()
                ->selectCollection('account_profile_outbox')
                ->countDocuments(['command_id' => $commandId]),
        );
    }

    public function test_account_profile_update_media_removals_refresh_map_poi_projection_urls(): void
    {
        $this->markTestSkipped(
            'Deferred to foundation_documentation/todos/active/v0.4.1/TODO-v0.4.1-account-profile-gallery-outbox-durability.md during the Tuesday, July 21, 2026 v0.4.0 promotion replay.'
        );

        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'POI Media Remove',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $avatarUrl = $createResponse->json('data.account_profile.avatar_url');
        $coverUrl = $createResponse->json('data.account_profile.cover_url');
        $this->assertNotEmpty($avatarUrl);
        $this->assertNotEmpty($coverUrl);
        $profile = AccountProfile::query()->findOrFail($profileId);

        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->first();

        $this->assertNotNull($projection);
        $this->assertSame($profile->avatar_url, $projection?->avatar_url);
        $this->assertSame($profile->cover_url, $projection?->cover_url);
        $this->assertStringEndsWith((string) $projection?->avatar_url, (string) $avatarUrl);
        $this->assertStringEndsWith((string) $projection?->cover_url, (string) $coverUrl);

        $removeResponse = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'remove_avatar' => true,
                'remove_cover' => true,
            ],
            $this->getHeaders()
        );

        $removeResponse->assertStatus(200);
        $removeResponse->assertJsonPath('data.avatar_url', null);
        $removeResponse->assertJsonPath('data.cover_url', null);

        $projection = MapPoi::query()
            ->where('ref_type', 'account_profile')
            ->where('ref_id', $profileId)
            ->first();

        $this->assertNotNull($projection);
        $this->assertNull($projection?->avatar_url);
        $this->assertNull($projection?->cover_url);
    }

    public function test_account_profile_remove_avatar_and_cover_clears_media(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Remove',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'personal',
                'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $avatarPath = $this->assertMediaStored($profileId, 'avatar');
        $coverPath = $this->assertMediaStored($profileId, 'cover');

        $removeResponse = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'remove_avatar' => true,
                'remove_cover' => true,
            ],
            $this->getHeaders()
        );

        $removeResponse->assertStatus(200);
        $this->assertNull($removeResponse->json('data.avatar_url'));
        $this->assertNull($removeResponse->json('data.cover_url'));
        Storage::disk('public')->assertMissing($avatarPath);
        Storage::disk('public')->assertMissing($coverPath);
    }

    public function test_account_profile_show_and_public_detail_include_gallery_readback_while_avatar_cover_and_gallery_share_the_canonical_media_directory(): void
    {
        Storage::fake('public');

        $createResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Profile Gallery Surface',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.0,
                    'lng' => -40.0,
                ],
                'avatar' => UploadedFile::fake()->image('avatar.png', 240, 240),
                'cover' => UploadedFile::fake()->image('cover.jpg', 1800, 900),
            ],
        );

        $createResponse->assertStatus(201);
        $profileId = (string) $createResponse->json('data.account_profile.id');
        $slug = (string) $createResponse->json('data.account_profile.slug');
        $this->assertNotSame('', $slug);
        $this->assertMediaUrlHealthy($createResponse->json('data.account_profile.avatar_url'));
        $this->assertMediaUrlHealthy($createResponse->json('data.account_profile.cover_url'));

        $galleryResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}/gallery",
            [
                '_method' => 'PATCH',
                'gallery_groups' => json_encode([
                    [
                        'group_id' => 'ambientes',
                        'subtitle' => 'Ambientes',
                        'items' => [
                            [
                                'item_id' => 'hall-principal',
                                'description' => 'Hall principal',
                                'upload' => 'upload_hall',
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                'upload_hall' => UploadedFile::fake()->image('hall.jpg', 2200, 1400),
            ],
        );

        $galleryResponse->assertOk();
        $galleryResponse->assertJsonPath('data.gallery_groups.0.group_id', 'ambientes');
        $galleryResponse->assertJsonPath('data.gallery_groups.0.items.0.item_id', 'hall-principal');

        $adminShow = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            $this->getHeaders()
        );
        $adminShow->assertOk();
        $adminShow->assertJsonPath('data.gallery_groups.0.group_id', 'ambientes');
        $adminShow->assertJsonPath('data.gallery_groups.0.items.0.item_id', 'hall-principal');

        $publicShow = $this->getJson("{$this->base_api_tenant}account_profiles/{$slug}");
        $publicShow->assertOk();
        $publicShow->assertJsonPath('data.gallery_groups.0.group_id', 'ambientes');
        $publicShow->assertJsonPath('data.gallery_groups.0.items.0.item_id', 'hall-principal');
        $publicShow->assertJsonPath(
            'data.gallery_groups.0.items.0.modal_url',
            $adminShow->json('data.gallery_groups.0.items.0.modal_url')
        );

        $tenant = Tenant::current();
        $tenantSlug = $tenant?->slug ?? $this->tenant->subdomain;
        $directory = "tenants/{$tenantSlug}/account_profiles/{$profileId}";
        $files = Storage::disk('public')->files($directory);

        $this->assertNotEmpty(
            collect($files)->first(fn (string $path): bool => str_contains(basename($path), 'avatar.'))
        );
        $this->assertNotEmpty(
            collect($files)->first(fn (string $path): bool => str_contains(basename($path), 'cover.'))
        );
        $this->assertNotEmpty(
            collect($files)->first(fn (string $path): bool => str_contains(basename($path), 'gallery-item-hall-principal.'))
        );
    }

    public function test_account_profile_create_rejects_unknown_profile_type(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Unknown Profile',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'unknown_type',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.profile_type'));
    }

    public function test_legacy_account_profile_create_route_returns_policy_rejection(): void
    {
        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_profiles",
            [
                'account_id' => '605b9b3b8f1d2c6d88f4c123',
                'profile_type' => 'personal',
                'display_name' => 'Missing Account',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'tenant_admin_onboarding_required');
        $response->assertJsonPath('meta.use_endpoint', '/admin/api/v1/account_onboardings');
    }

    public function test_account_profile_create_forbidden_without_ability(): void
    {
        $user = LandlordUser::query()->firstOrFail();

        Sanctum::actingAs($user, ['account-users:view']);

        $response = $this->postJson("{$this->base_tenant_api_admin}account_onboardings", [
            'name' => 'Personal',
            'ownership_state' => 'tenant_owned',
            'profile_type' => 'personal',
        ]);

        $response->assertStatus(403);
    }

    public function test_account_profile_update_rejects_invalid_profile_type(): void
    {
        $tenant = Tenant::query()->firstOrFail();
        $tenant->makeCurrent();
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile A',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;
        $this->assertNotEmpty($profileId);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'profile_type' => 'invalid_type',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.profile_type'));
    }

    public function test_account_onboarding_persists_contact_channels_when_type_capability_is_enabled(): void
    {
        $this->enableContactChannelsCapability('venue');

        $response = $this->postJson(
            "{$this->base_tenant_api_admin}account_onboardings",
            [
                'name' => 'Contact Venue',
                'ownership_state' => 'tenant_owned',
                'profile_type' => 'venue',
                'location' => [
                    'lat' => -20.3155,
                    'lng' => -40.3128,
                ],
                'contact_mode' => 'own',
                'contact_channels' => [
                    [
                        'draft_key' => 'email-main',
                        'type' => 'email',
                        'value' => 'contato@venue.example',
                        'title' => 'E-mail principal',
                    ],
                    [
                        'draft_key' => 'whatsapp-main',
                        'type' => 'whatsapp',
                        'value' => '+55 (27) 99999-1111',
                        'title' => 'WhatsApp principal',
                        'metadata' => [
                            'initial_messages' => [
                                [
                                    'id' => 'cta-ola',
                                    'cta' => 'Olá',
                                    'mensagem' => 'Olá! Gostaria de saber mais.',
                                ],
                            ],
                        ],
                    ],
                ],
                'contact_bubble_channel_draft_key' => 'whatsapp-main',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.account_profile.contact_mode', 'own');
        $emailId = $response->json('data.account_profile.contact_channels.0.id');
        $whatsappId = $response->json('data.account_profile.contact_channels.1.id');
        $this->assertIsString($emailId);
        $this->assertIsString($whatsappId);
        $this->assertNotSame($emailId, $whatsappId);
        $response->assertJsonPath('data.account_profile.contact_bubble_channel_id', $whatsappId);
        $response->assertJsonPath('data.account_profile.effective_contact_channels.0.id', $emailId);
        $response->assertJsonPath('data.account_profile.effective_contact_channels.1.id', $whatsappId);
        $response->assertJsonPath('data.account_profile.effective_contact_bubble_channel.id', $whatsappId);
    }

    public function test_account_profile_update_rejects_contact_channels_when_type_capability_is_disabled(): void
    {
        TenantProfileType::create([
            'type' => 'plain',
            'label' => 'Plain',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => true,
                'is_publicly_discoverable' => true,
                'is_favoritable' => true,
                'has_contact_channels' => false,
            ],
        ]);

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'plain',
            'display_name' => 'No Contact Capability',
            'slug' => 'no-contact-capability',
            'is_active' => true,
        ])->fresh();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'contact_mode' => 'own',
                'contact_channels' => [
                    [
                        'id' => 'email-main',
                        'type' => 'email',
                        'value' => 'blocked@example.org',
                    ],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_channels']);
    }

    public function test_account_profile_update_requires_source_for_mirrored_contact_mode(): void
    {
        $this->enableContactChannelsCapability('venue');

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Mirror Source Required',
            'slug' => 'mirror-source-required',
            'is_active' => true,
        ])->fresh();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'contact_mode' => 'mirrored_account_profile',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_source_account_profile_id']);
    }

    public function test_account_profile_update_rejects_invalid_whatsapp_contact_channel(): void
    {
        $this->enableContactChannelsCapability('venue');

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Invalid WhatsApp Venue',
            'slug' => 'invalid-whatsapp-venue',
            'is_active' => true,
        ])->fresh();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'contact_mode' => 'own',
                'contact_channels' => [
                    [
                        'draft_key' => 'whatsapp-main',
                        'type' => 'whatsapp',
                        'value' => 'not-a-whatsapp-target',
                    ],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_channels.0.value']);
    }

    public function test_account_profile_update_rejects_oversized_whatsapp_initial_message_text(): void
    {
        $this->enableContactChannelsCapability('venue');
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Oversized WhatsApp Message',
            'slug' => 'oversized-whatsapp-message',
            'is_active' => true,
        ])->fresh();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'contact_channels' => [[
                    'draft_key' => 'oversized-whatsapp',
                    'type' => 'whatsapp',
                    'value' => '+55 (27) 99999-1111',
                    'metadata' => ['initial_messages' => [[
                        'id' => 'oversized',
                        'cta' => 'Mensagem longa',
                        'mensagem' => str_repeat('M', 1001),
                    ]]],
                ]],
            ],
            $this->getHeaders(),
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'contact_channels.0.metadata.initial_messages.0.mensagem',
        ]);
    }

    public function test_account_profile_update_rejects_non_whatsapp_bubble_selection(): void
    {
        $this->enableContactChannelsCapability('venue');

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Bubble Validation Venue',
            'slug' => 'bubble-validation-venue',
            'is_active' => true,
        ])->fresh();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $profile->_id,
            [
                'contact_mode' => 'own',
                'contact_channels' => [
                    [
                        'draft_key' => 'email-main',
                        'type' => 'email',
                        'value' => 'contato@bubble.example',
                    ],
                    [
                        'draft_key' => 'whatsapp-main',
                        'type' => 'whatsapp',
                        'value' => '+55 (27) 99999-1111',
                    ],
                ],
                'contact_bubble_channel_draft_key' => 'email-main',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['contact_bubble_channel_id']);
    }

    public function test_contact_channel_draft_ids_are_server_owned_and_bubble_patch_is_tri_state(): void
    {
        $this->enableContactChannelsCapability('venue');
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Draft Identity Venue',
            'slug' => 'draft-identity-venue',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;

        $created = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'contact_channels' => [
                    [
                        'draft_key' => 'email-primary',
                        'type' => 'email',
                        'value' => 'primary@example.test',
                    ],
                    [
                        'draft_key' => 'whatsapp-primary',
                        'type' => 'whatsapp',
                        'value' => '+55 (27) 99999-1111',
                    ],
                ],
                'contact_bubble_channel_draft_key' => 'whatsapp-primary',
            ],
            $this->getHeaders(),
        );

        $created->assertOk();
        $emailId = $created->json('data.contact_channels.0.id');
        $whatsappId = $created->json('data.contact_channels.1.id');
        $this->assertIsString($emailId);
        $this->assertIsString($whatsappId);
        $created->assertJsonPath('data.contact_bubble_channel_id', $whatsappId);

        $omitted = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            ['display_name' => 'Draft Identity Venue Renamed'],
            $this->getHeaders(),
        );
        $omitted->assertOk();
        $omitted->assertJsonPath('data.contact_bubble_channel_id', $whatsappId);

        $cleared = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            ['contact_bubble_channel_id' => null],
            $this->getHeaders(),
        );
        $cleared->assertOk();
        $cleared->assertJsonPath('data.contact_bubble_channel_id', null);

        $unknownId = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'contact_channels' => [
                    ['id' => 'contact-unknown', 'type' => 'email', 'value' => 'unknown@example.test'],
                ],
            ],
            $this->getHeaders(),
        );
        $unknownId->assertStatus(422);
        $unknownId->assertJsonValidationErrors(['contact_channels.0.id']);

        $typeMutation = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'contact_channels' => [
                    ['id' => $emailId, 'type' => 'whatsapp', 'value' => '+55 (27) 99999-1111'],
                ],
            ],
            $this->getHeaders(),
        );
        $typeMutation->assertStatus(422);
        $typeMutation->assertJsonValidationErrors(['contact_channels.0.type']);
    }

    public function test_contact_channel_mirrors_are_one_hop_and_stale_pointers_fail_closed_without_reuse(): void
    {
        $this->enableContactChannelsCapability('venue');
        $source = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Own Contact Source',
            'slug' => 'own-contact-source',
            'is_active' => true,
        ])->fresh();

        $sourceWrite = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $source->_id,
            [
                'contact_channels' => [[
                    'draft_key' => 'source-whatsapp',
                    'type' => 'whatsapp',
                    'value' => '+55 (27) 99999-1111',
                ]],
                'contact_bubble_channel_draft_key' => 'source-whatsapp',
            ],
            $this->getHeaders(),
        );
        $sourceWrite->assertOk();
        $removedSourceChannelId = $sourceWrite->json('data.contact_bubble_channel_id');
        $this->assertIsString($removedSourceChannelId);

        $mirrorAccount = Account::create([
            'name' => 'One Hop Mirror Account',
            'document' => 'DOC-ONE-HOP-'.uniqid('', true),
        ]);
        $mirror = AccountProfile::create([
            'account_id' => (string) $mirrorAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'One Hop Mirror',
            'slug' => 'one-hop-mirror',
            'is_active' => true,
        ])->fresh();

        $mirrorWrite = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $mirror->_id,
            [
                'contact_mode' => 'mirrored_account_profile',
                'contact_source_account_profile_id' => (string) $source->_id,
                'contact_bubble_channel_id' => $removedSourceChannelId,
            ],
            $this->getHeaders(),
        );
        $mirrorWrite->assertOk();

        $sourceRemoval = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $source->_id,
            [
                'contact_channels' => [],
                'contact_bubble_channel_id' => null,
            ],
            $this->getHeaders(),
        );
        $sourceRemoval->assertOk();

        $staleMirror = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $mirror->_id,
            $this->getHeaders(),
        );
        $staleMirror->assertOk();
        $staleMirror->assertJsonPath('data.contact_bubble_channel_id', $removedSourceChannelId);
        $staleMirror->assertJsonPath('data.effective_contact_channels', []);
        $staleMirror->assertJsonPath('data.effective_contact_bubble_channel', null);

        $replacementSource = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $source->_id,
            [
                'contact_channels' => [[
                    'draft_key' => 'replacement-whatsapp',
                    'type' => 'whatsapp',
                    'value' => '+55 (27) 98888-2222',
                ]],
            ],
            $this->getHeaders(),
        );
        $replacementSource->assertOk();
        $replacementSourceChannelId = $replacementSource->json('data.contact_channels.0.id');
        $this->assertIsString($replacementSourceChannelId);
        $this->assertNotSame($removedSourceChannelId, $replacementSourceChannelId);

        $replacementMirror = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $mirror->_id,
            $this->getHeaders(),
        );
        $replacementMirror->assertOk();
        $replacementMirror->assertJsonPath('data.effective_contact_channels.0.id', $replacementSourceChannelId);
        $replacementMirror->assertJsonPath('data.effective_contact_bubble_channel', null);

        $nestedAccount = Account::create([
            'name' => 'Nested Mirror Account',
            'document' => 'DOC-NESTED-'.uniqid('', true),
        ]);
        $nested = AccountProfile::create([
            'account_id' => (string) $nestedAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Mirror Candidate',
            'slug' => 'nested-mirror-candidate',
            'is_active' => true,
        ])->fresh();
        $nestedWrite = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $nested->_id,
            [
                'contact_mode' => 'mirrored_account_profile',
                'contact_source_account_profile_id' => (string) $mirror->_id,
            ],
            $this->getHeaders(),
        );
        $nestedWrite->assertStatus(422);
        $nestedWrite->assertJsonValidationErrors(['contact_source_account_profile_id']);

        $legacyNestedAccount = Account::create([
            'name' => 'Legacy Nested Mirror Account',
            'document' => 'DOC-LEGACY-NESTED-'.uniqid('', true),
        ]);
        $legacyNested = AccountProfile::create([
            'account_id' => (string) $legacyNestedAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Legacy Nested Mirror',
            'slug' => 'legacy-nested-mirror',
            'contact_mode' => 'mirrored_account_profile',
            'contact_source_account_profile_id' => (string) $mirror->_id,
            'contact_bubble_channel_id' => $replacementSourceChannelId,
            'is_active' => true,
        ])->fresh();
        $legacyRead = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $legacyNested->_id,
            $this->getHeaders(),
        );
        $legacyRead->assertOk();
        $legacyRead->assertJsonPath('data.effective_contact_source', null);
        $legacyRead->assertJsonPath('data.effective_contact_channels', []);
        $legacyRead->assertJsonPath('data.effective_contact_bubble_channel', null);
    }

    public function test_account_profile_update_accepts_four_character_public_display_name(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile A',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;
        $this->assertNotEmpty($profileId);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'display_name' => 'Bela',
            ],
            $this->getHeaders()
        );

        $response->assertOk();
        $response->assertJsonPath('data.display_name', 'Bela');
    }

    public function test_account_profile_edit_journey_preserves_display_name_through_gallery_follow_through(): void
    {
        Storage::fake('public');

        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Gallery Journey Venue',
            'slug' => 'gallery-journey-venue',
            'visibility' => 'public',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;
        $this->assertNotEmpty($profileId);

        $seedGalleryResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}/gallery",
            [
                '_method' => 'PATCH',
                'gallery_groups' => json_encode([
                    [
                        'group_id' => 'ambientes',
                        'subtitle' => 'Ambientes',
                        'items' => [
                            [
                                'item_id' => 'hall-principal',
                                'description' => 'Hall principal',
                                'upload' => 'upload_hall',
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                'upload_hall' => UploadedFile::fake()->image('hall.jpg', 2200, 1400),
            ],
        );
        $seedGalleryResponse->assertOk();
        $seedGalleryResponse->assertJsonPath('data.gallery_groups.0.items.0.item_id', 'hall-principal');

        $updateResponse = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'display_name' => 'Renamed Journey Venue',
            ],
            $this->getHeaders()
        );

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.display_name', 'Renamed Journey Venue');

        $clearGalleryResponse = $this->withHeaders($this->getMultipartHeaders())->post(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}/gallery",
            [
                '_method' => 'PATCH',
                'gallery_groups' => json_encode([], JSON_THROW_ON_ERROR),
            ],
        );

        $clearGalleryResponse->assertOk();
        $clearGalleryResponse->assertJsonPath('data.display_name', 'Renamed Journey Venue');
        $clearGalleryResponse->assertJsonPath('data.gallery_groups', []);

        $adminShow = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            $this->getHeaders()
        );
        $adminShow->assertOk();
        $adminShow->assertJsonPath('data.display_name', 'Renamed Journey Venue');
        $adminShow->assertJsonPath('data.gallery_groups', []);
    }

    public function test_account_profile_update_rejects_display_name_shorter_than_three_visible_characters(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile A',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;
        $this->assertNotEmpty($profileId);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'display_name' => 'An',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['display_name']);
    }

    public function test_account_profile_update_rejects_whitespace_only_display_name(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile A',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;
        $this->assertNotEmpty($profileId);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'display_name' => '   ',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['display_name']);
    }

    public function test_account_profile_update_allows_slug_change(): void
    {
        $profile = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile Slug',
            'is_active' => true,
        ])->fresh();
        $profileId = (string) $profile->_id;
        $this->assertNotEmpty($profileId);

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$profileId}",
            [
                'slug' => 'profile-slug-custom',
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.slug', 'profile-slug-custom');
    }

    public function test_account_profile_update_rejects_duplicate_slug(): void
    {
        $this->markTestSkipped(
            'Deferred to foundation_documentation/todos/active/v0.4.1/TODO-v0.4.1-account-profile-duplicate-slug-update-validation.md during the Tuesday, July 21, 2026 v0.4.0 promotion replay.'
        );

        $primary = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Primary Slug',
            'is_active' => true,
        ])->fresh();

        $otherAccount = Account::create([
            'name' => 'Account Slug Other',
            'document' => 'DOC-SLUG-OTHER',
        ]);
        $secondary = AccountProfile::create([
            'account_id' => (string) $otherAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Secondary Slug',
            'is_active' => true,
        ])->fresh();

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $primary->_id,
            [
                'slug' => (string) ($secondary->slug ?? ''),
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    public function test_account_profile_update_persists_nested_profile_group_metadata_and_member_deltas_in_order(): void
    {
        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Parent Venue',
            'slug' => 'nested-parent-venue',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();

        $partnerA = $this->createNestedProfileFixture('Nested Partner A', 'nested-partner-a');
        $partnerB = $this->createNestedProfileFixture('Nested Partner B', 'nested-partner-b');
        $sponsor = $this->createNestedProfileFixture('Nested Sponsor', 'nested-sponsor');

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            [
                'aggregate_revision' => max(1, (int) ($parent->aggregate_revision ?? 1)),
                'nested_profile_groups' => [
                    [
                        'id' => 'patrocinadores',
                        'label' => 'Patrocinadores',
                        'order' => 1,
                    ],
                    [
                        'id' => 'parceiros',
                        'label' => 'Parceiros',
                        'order' => 0,
                    ],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.nested_profile_groups.0.id', 'parceiros');
        $response->assertJsonPath('data.nested_profile_groups.0.label', 'Parceiros');
        $response->assertJsonPath('data.nested_profile_groups.1.id', 'patrocinadores');
        $response->assertJsonPath('data.nested_profile_groups.0.member_count', 0);
        $response->assertJsonPath('data.nested_profile_groups.1.member_count', 0);

        $metadataRevision = (int) $response->json('data.aggregate_revision');

        $partnersDelta = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id."/nested_profile_groups/parceiros/members",
            [
                'aggregate_revision' => $metadataRevision,
                'add_ids' => [
                    (string) $partnerB->_id,
                    (string) $partnerA->_id,
                ],
            ],
            $this->getHeaders()
        );
        $partnersDelta->assertOk();
        $partnersDelta->assertJsonPath('data.member_count', 2);

        $sponsorsDelta = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id."/nested_profile_groups/patrocinadores/members",
            [
                'aggregate_revision' => (int) $partnersDelta->json('data.aggregate_revision'),
                'add_ids' => [(string) $sponsor->_id],
            ],
            $this->getHeaders()
        );
        $sponsorsDelta->assertOk();
        $sponsorsDelta->assertJsonPath('data.member_count', 1);

        $readback = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            $this->getHeaders()
        );

        $readback->assertStatus(200);
        $readback->assertJsonPath('data.aggregate_revision', (int) $sponsorsDelta->json('data.aggregate_revision'));
        $readback->assertJsonPath('data.nested_profile_groups.0.id', 'parceiros');
        $readback->assertJsonPath('data.nested_profile_groups.1.id', 'patrocinadores');
        $readback->assertJsonPath('data.nested_profile_groups.0.member_count', 2);
        $readback->assertJsonPath('data.nested_profile_groups.1.member_count', 1);

        $partnersPage = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id."/nested_profile_groups/parceiros/members",
            $this->getHeaders()
        );
        $partnersPage->assertOk();
        $partnersPage->assertJsonPath('aggregate_revision', (int) $sponsorsDelta->json('data.aggregate_revision'));
        $partnersPage->assertJsonPath('data.0.id', (string) $partnerB->_id);
        $partnersPage->assertJsonPath('data.1.id', (string) $partnerA->_id);

        $sponsorsPage = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id."/nested_profile_groups/patrocinadores/members",
            $this->getHeaders()
        );
        $sponsorsPage->assertOk();
        $sponsorsPage->assertJsonPath('data.0.id', (string) $sponsor->_id);
    }

    public function test_relation_admission_touches_contact_and_nested_targets_before_the_parent_commit(): void
    {
        $this->enableContactChannelsCapability('venue');

        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Relation Admission Parent',
            'slug' => 'relation-admission-parent',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();
        $contactSource = $this->createNestedProfileFixture(
            'Relation Admission Contact Source',
            'relation-admission-contact-source',
            ['contact_mode' => 'own'],
        );
        $nestedMember = $this->createNestedProfileFixture(
            'Relation Admission Nested Member',
            'relation-admission-nested-member',
        );

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$parent->_id}",
            [
                'aggregate_revision' => max(1, (int) ($parent->aggregate_revision ?? 1)),
                'contact_mode' => 'mirrored_account_profile',
                'contact_source_account_profile_id' => (string) $contactSource->_id,
                'nested_profile_groups' => [[
                    'id' => 'admitted-members',
                    'label' => 'Admitted members',
                ]],
            ],
            $this->getHeaders(),
        );

        $response->assertOk();
        $delta = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/{$parent->_id}/nested_profile_groups/admitted-members/members",
            [
                'aggregate_revision' => (int) $response->json('data.aggregate_revision'),
                'add_ids' => [(string) $nestedMember->_id],
            ],
            $this->getHeaders(),
        );

        $delta->assertOk();
        $this->assertSame((int) $delta->json('data.aggregate_revision'), (int) $parent->fresh()->aggregate_revision);
        $this->assertSame(1, (int) $contactSource->fresh()->lifecycle_fence_revision);
        $this->assertSame(1, (int) $nestedMember->fresh()->lifecycle_fence_revision);
    }

    public function test_relation_admission_rejects_a_tenant_b_profile_id_on_a_tenant_a_admin_patch(): void
    {
        $this->enableContactChannelsCapability('venue');

        $tenantA = Tenant::current();
        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Cross Tenant Admission Parent',
            'slug' => 'cross-tenant-admission-parent',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();
        $parentBefore = $parent->only([
            'display_name',
            'contact_mode',
            'contact_source_account_profile_id',
            'nested_profile_groups',
            'aggregate_revision',
        ]);

        Queue::fake([RebuildTenantEnvironmentSnapshotJob::class]);
        $tenantB = $this->ensureCanonicalTenantExists($this->landlord->tenant_secondary);
        $tenantB->makeCurrent();
        $accountB = Account::create([
            'name' => 'Cross Tenant Admission Account',
            'document' => strtoupper('B'.bin2hex(random_bytes(7))),
        ]);
        $tenantBProfile = AccountProfile::create([
            'account_id' => (string) $accountB->_id,
            'profile_type' => 'venue',
            'display_name' => 'Tenant B Only Profile',
            'slug' => 'tenant-b-only-profile-'.bin2hex(random_bytes(4)),
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();
        $tenantBProfileId = (string) $tenantBProfile->_id;
        $tenantBProfileBefore = $tenantBProfile->only([
            'display_name',
            'slug',
            'lifecycle_fence_revision',
        ]);

        $tenantA->makeCurrent();
        $tenantAUrl = "http://{$tenantA->subdomain}.{$this->host}/admin/api/v1/account_profiles/{$parent->_id}";
        $contactCommandId = 'u07a-cross-tenant-contact-'.uniqid('', true);
        $contactResponse = $this->patchJson(
            $tenantAUrl,
            [
                'contact_mode' => 'mirrored_account_profile',
                'contact_source_account_profile_id' => $tenantBProfileId,
            ],
            [...$this->getHeaders(), 'X-Request-Id' => $contactCommandId],
        );

        $contactResponse->assertStatus(422);
        $this->assertSame($parentBefore, $parent->fresh()->only(array_keys($parentBefore)));

        $database = DB::connection('tenant')->getDatabase();
        $this->assertNull(
            $database->selectCollection('account_profile_command_receipts')->findOne(['_id' => $contactCommandId]),
        );
        $this->assertNull(
            $database->selectCollection('account_profile_outbox')->findOne(['command_id' => $contactCommandId]),
        );

        $tenantB->makeCurrent();
        $this->assertSame(
            $tenantBProfileBefore,
            AccountProfile::query()->findOrFail($tenantBProfileId)->only(array_keys($tenantBProfileBefore)),
        );

        $tenantA->makeCurrent();
        $nestedCommandId = 'u07a-cross-tenant-nested-'.uniqid('', true);
        $nestedResponse = $this->patchJson(
            $tenantAUrl,
            [
                'nested_profile_groups' => [[
                    'id' => 'cross-tenant-members',
                    'label' => 'Cross tenant members',
                    'account_profile_ids' => [$tenantBProfileId],
                ]],
            ],
            [...$this->getHeaders(), 'X-Request-Id' => $nestedCommandId],
        );

        $nestedResponse->assertStatus(422);
        $this->assertSame($parentBefore, $parent->fresh()->only(array_keys($parentBefore)));

        $database = DB::connection('tenant')->getDatabase();
        $this->assertNull(
            $database->selectCollection('account_profile_command_receipts')->findOne(['_id' => $nestedCommandId]),
        );
        $this->assertNull(
            $database->selectCollection('account_profile_outbox')->findOne(['command_id' => $nestedCommandId]),
        );

        $tenantB->makeCurrent();
        $this->assertSame(
            $tenantBProfileBefore,
            AccountProfile::query()->findOrFail($tenantBProfileId)->only(array_keys($tenantBProfileBefore)),
        );
    }

    public function test_account_cascade_delete_cleans_previously_admitted_nested_profile_references(): void
    {
        $targetAccount = Account::create([
            'name' => 'Nested Cascade Target Account',
            'document' => 'DOC-NESTED-CASCADE-TARGET-'.uniqid('', true),
            'ownership_state' => 'unmanaged',
        ]);
        $target = AccountProfile::create([
            'account_id' => (string) $targetAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Cascade Target',
            'slug' => 'nested-cascade-target-'.uniqid('', true),
            'is_active' => true,
        ]);
        $survivingAccount = Account::create([
            'name' => 'Nested Cascade Surviving Account',
            'document' => 'DOC-NESTED-CASCADE-SURVIVING-'.uniqid('', true),
            'ownership_state' => 'unmanaged',
        ]);
        $surviving = AccountProfile::create([
            'account_id' => (string) $survivingAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Cascade Surviving',
            'slug' => 'nested-cascade-surviving-'.uniqid('', true),
            'is_active' => true,
        ]);
        $parentAccount = Account::create([
            'name' => 'Nested Cascade Parent Account',
            'document' => 'DOC-NESTED-CASCADE-PARENT-'.uniqid('', true),
            'ownership_state' => 'unmanaged',
        ]);
        $parent = AccountProfile::create([
            'account_id' => (string) $parentAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Cascade Parent',
            'slug' => 'nested-cascade-parent-'.uniqid('', true),
            'is_active' => true,
        ]);

        app(AccountProfileManagementService::class)->update(
            $parent,
            [
                'nested_profile_groups' => [[
                    'id' => 'cascade-members',
                    'label' => 'Cascade Members',
                    'account_profile_ids' => [(string) $target->_id, (string) $surviving->_id],
                ]],
            ],
            commandId: 'u07a-nested-cascade-relation-'.uniqid('', true),
        );

        $cascadeCommandId = 'u07a-nested-cascade-delete-'.uniqid('', true);
        app(AccountManagementService::class)->delete(
            $targetAccount,
            commandId: $cascadeCommandId,
        );

        $parent->refresh();
        $this->assertSame(
            [(string) $surviving->_id],
            $parent->nested_profile_groups[0]['account_profile_ids'] ?? [],
        );
        $this->assertNotNull(AccountProfile::onlyTrashed()->find((string) $target->_id));
        $cleanupReceipt = DB::connection('tenant')->getDatabase()
            ->selectCollection('account_profile_command_receipts')
            ->findOne(['_id' => "{$cascadeCommandId}:reference-cleanup:".(string) $parent->_id]);
        $this->assertNotNull($cleanupReceipt);
        $cleanupOutbox = DB::connection('tenant')->getDatabase()
            ->selectCollection('account_profile_outbox')
            ->findOne(['_id' => $cleanupReceipt['outbox_event_id'] ?? '']);
        $this->assertSame('completed', $cleanupOutbox['delivery_state'] ?? null);
    }

    public function test_profile_delete_cleans_previously_admitted_nested_profile_references(): void
    {
        $targetAccount = Account::create([
            'name' => 'Nested Direct Target Account',
            'document' => 'DOC-NESTED-DIRECT-TARGET-'.uniqid('', true),
            'ownership_state' => 'unmanaged',
        ]);
        $target = AccountProfile::create([
            'account_id' => (string) $targetAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Direct Target',
            'slug' => 'nested-direct-target-'.uniqid('', true),
            'is_active' => true,
        ]);
        $parentAccount = Account::create([
            'name' => 'Nested Direct Parent Account',
            'document' => 'DOC-NESTED-DIRECT-PARENT-'.uniqid('', true),
            'ownership_state' => 'unmanaged',
        ]);
        $parent = AccountProfile::create([
            'account_id' => (string) $parentAccount->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Direct Parent',
            'slug' => 'nested-direct-parent-'.uniqid('', true),
            'is_active' => true,
        ]);

        app(AccountProfileManagementService::class)->update(
            $parent,
            [
                'nested_profile_groups' => [[
                    'id' => 'direct-members',
                    'label' => 'Direct Members',
                    'account_profile_ids' => [(string) $target->_id],
                ]],
            ],
            commandId: 'u07a-nested-direct-relation-'.uniqid('', true),
        );
        app(AccountProfileManagementService::class)->update(
            $target,
            ['is_active' => false],
            commandId: 'u07a-nested-direct-deactivate-'.uniqid('', true),
        );

        $deleteCommandId = 'u07a-nested-direct-delete-'.uniqid('', true);
        app(AccountProfileLifecycleService::class)->delete($target, $deleteCommandId);

        $parent->refresh();
        $this->assertSame([], $parent->nested_profile_groups[0]['account_profile_ids'] ?? []);
        $this->assertNotNull(AccountProfile::onlyTrashed()->find((string) $target->_id));
        $cleanupReceipt = DB::connection('tenant')->getDatabase()
            ->selectCollection('account_profile_command_receipts')
            ->findOne(['_id' => "{$deleteCommandId}:reference-cleanup:".(string) $parent->_id]);
        $this->assertNotNull($cleanupReceipt);
        $cleanupOutbox = DB::connection('tenant')->getDatabase()
            ->selectCollection('account_profile_outbox')
            ->findOne(['_id' => $cleanupReceipt['outbox_event_id'] ?? '']);
        $this->assertSame('completed', $cleanupOutbox['delivery_state'] ?? null);
    }

    public function test_account_profile_admin_readback_keeps_linked_selection_summaries_in_one_contract(): void
    {
        $this->enableContactChannelsCapability('venue');
        TenantProfileType::create([
            'type' => 'queryable_only',
            'label' => 'Queryable only',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => false,
                'is_favoritable' => false,
                'is_publicly_discoverable' => false,
                'is_poi_enabled' => false,
                'has_events' => false,
                'has_contact_channels' => false,
            ],
        ]);

        $parent = $this->createNestedProfileFixture('Linked Summary Parent', 'linked-summary-parent');
        $queryable = $this->createNestedProfileFixture(
            'Queryable Linked Profile',
            'queryable-linked-profile',
            ['profile_type' => 'queryable_only'],
        );
        $inactive = $this->createNestedProfileFixture(
            'Inactive Linked Profile',
            'inactive-linked-profile',
            ['profile_type' => 'queryable_only', 'is_active' => false],
        );
        $deleted = $this->createNestedProfileFixture(
            'Deleted Linked Profile',
            'deleted-linked-profile',
            ['profile_type' => 'queryable_only'],
        );
        $deleted->delete();
        $contactSource = $this->createNestedProfileFixture(
            'Contact Linked Profile',
            'contact-linked-profile',
            ['contact_mode' => 'own'],
        );
        $missingId = (string) new ObjectId;

        $parent->forceFill([
            'nested_profile_groups' => [[
                'id' => 'linked',
                'label' => 'Linked profiles',
                'order' => 0,
                'account_profile_ids' => [
                    (string) $queryable->_id,
                    (string) $inactive->_id,
                    (string) $deleted->_id,
                    $missingId,
                ],
            ]],
            'contact_mode' => 'mirrored_account_profile',
            'contact_source_account_profile_id' => (string) $contactSource->_id,
            'contact_channels' => [],
            'contact_bubble_channel_id' => null,
        ])->save();

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            $this->getHeaders(),
        );

        $response->assertOk();
        $response->assertJsonPath('data.nested_profile_groups.0.account_profile_summaries.0', [
            'id' => (string) $queryable->_id,
            'display_name' => 'Queryable Linked Profile',
            'is_queryable_candidate' => true,
            'is_contact_capable_candidate' => false,
        ]);
        $response->assertJsonPath('data.nested_profile_groups.0.account_profile_summaries.1', [
            'id' => (string) $inactive->_id,
            'display_name' => 'Inactive Linked Profile',
            'is_queryable_candidate' => false,
            'is_contact_capable_candidate' => false,
        ]);
        $response->assertJsonPath('data.nested_profile_groups.0.account_profile_summaries.2', [
            'id' => (string) $deleted->_id,
            'display_name' => 'Deleted Linked Profile',
            'is_queryable_candidate' => false,
            'is_contact_capable_candidate' => false,
        ]);
        $response->assertJsonPath('data.nested_profile_groups.0.account_profile_summaries.3', [
            'id' => $missingId,
            'display_name' => null,
            'is_queryable_candidate' => false,
            'is_contact_capable_candidate' => false,
        ]);
        $response->assertJsonPath('data.contact_source_account_profile', [
            'id' => (string) $contactSource->_id,
            'display_name' => 'Contact Linked Profile',
            'is_queryable_candidate' => true,
            'is_contact_capable_candidate' => true,
        ]);
    }

    public function test_account_profile_update_rejects_invalid_nested_profile_group_members(): void
    {
        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Invalid Parent',
            'slug' => 'nested-invalid-parent',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();
        $partner = $this->createNestedProfileFixture('Nested Duplicate', 'nested-duplicate');

        $duplicate = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            [
                'nested_profile_groups' => [
                    [
                        'id' => 'parceiros',
                        'label' => 'Parceiros',
                        'account_profile_ids' => [
                            (string) $partner->_id,
                            (string) $partner->_id,
                        ],
                    ],
                ],
            ],
            $this->getHeaders()
        );
        $duplicate->assertStatus(422);

        $selfLink = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            [
                'nested_profile_groups' => [
                    [
                        'id' => 'equipe',
                        'label' => 'Equipe',
                        'account_profile_ids' => [(string) $parent->_id],
                    ],
                ],
            ],
            $this->getHeaders()
        );
        $selfLink->assertStatus(422);
    }

    public function test_account_profile_update_rejects_non_queryable_nested_profile_group_members(): void
    {
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'hidden_guest'],
            ['capabilities' => [
                'is_queryable' => false,
                'is_publicly_navigable' => false,
                'is_publicly_discoverable' => false,
            ]]
        );

        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Parent',
            'slug' => 'nested-parent-non-queryable',
            'is_active' => true,
        ])->fresh();
        $hiddenMember = $this->createNestedProfileFixture(
            'Hidden Member',
            'hidden-member',
            ['profile_type' => 'hidden_guest']
        );

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            [
                'nested_profile_groups' => [
                    [
                        'id' => 'parceiros',
                        'label' => 'Parceiros',
                        'account_profile_ids' => [(string) $hiddenMember->_id],
                    ],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['nested_profile_groups']);
    }

    public function test_account_profile_update_rejects_nested_profile_group_limits(): void
    {
        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Limit Parent',
            'slug' => 'nested-limit-parent',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();

        $groups = [];
        for ($index = 0; $index <= InputConstraints::ACCOUNT_PROFILE_NESTED_GROUPS_MAX; $index++) {
            $groups[] = [
                'id' => "grupo-{$index}",
                'label' => "Grupo {$index}",
                'account_profile_ids' => [],
            ];
        }

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            ['nested_profile_groups' => $groups],
            $this->getHeaders()
        );

        $response->assertStatus(422);
    }

    public function test_account_profile_update_rejects_nested_profile_groups_when_type_capability_is_disabled(): void
    {
        TenantProfileType::create([
            'type' => 'plain',
            'label' => 'Plain',
            'allowed_taxonomies' => [],
            'capabilities' => [
                'is_favoritable' => false,
                'is_poi_enabled' => false,
                'has_nested_profile_groups' => false,
            ],
        ]);

        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'plain',
            'display_name' => 'Nested Disabled Parent',
            'slug' => 'nested-disabled-parent',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();
        $partner = $this->createNestedProfileFixture('Disabled Partner', 'disabled-partner');

        $response = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            [
                'nested_profile_groups' => [
                    [
                        'id' => 'parceiros',
                        'label' => 'Parceiros',
                        'account_profile_ids' => [(string) $partner->_id],
                    ],
                ],
            ],
            $this->getHeaders()
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['nested_profile_groups']);
    }

    public function test_public_account_profile_detail_projects_nested_groups_and_hides_empty_groups(): void
    {
        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Nested Public Parent',
            'slug' => 'nested-public-parent',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();

        $partnerA = $this->createNestedProfileFixture('Public Partner A', 'public-partner-a');
        $partnerB = $this->createNestedProfileFixture('Public Partner B', 'public-partner-b');
        $privatePartner = $this->createNestedProfileFixture(
            'Private Partner',
            'private-partner',
            ['visibility' => 'private']
        );

        $metadata = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            [
                'aggregate_revision' => max(1, (int) ($parent->aggregate_revision ?? 1)),
                'nested_profile_groups' => [
                    [
                        'id' => 'vazio',
                        'label' => 'Vazio',
                        'order' => 0,
                    ],
                    [
                        'id' => 'parceiros',
                        'label' => 'Parceiros',
                        'order' => 1,
                    ],
                    [
                        'id' => 'privados',
                        'label' => 'Privados',
                        'order' => 2,
                    ],
                ],
            ],
            $this->getHeaders()
        );
        $metadata->assertStatus(200);

        $partnersDelta = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id."/nested_profile_groups/parceiros/members",
            [
                'aggregate_revision' => (int) $metadata->json('data.aggregate_revision'),
                'add_ids' => [
                    (string) $partnerB->_id,
                    (string) $partnerA->_id,
                ],
            ],
            $this->getHeaders()
        );
        $partnersDelta->assertOk();

        $privateDelta = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id."/nested_profile_groups/privados/members",
            [
                'aggregate_revision' => (int) $partnersDelta->json('data.aggregate_revision'),
                'add_ids' => [(string) $privatePartner->_id],
            ],
            $this->getHeaders()
        );
        $privateDelta->assertOk();

        $response = $this->getJson("{$this->base_api_tenant}account_profiles/nested-public-parent");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.nested_profile_groups');
        $response->assertJsonPath('data.nested_profile_groups.0.id', 'parceiros');
        $response->assertJsonPath('data.nested_profile_groups.0.label', 'Parceiros');
        $response->assertJsonPath(
            'data.nested_profile_groups.0.members_path',
            '/api/v1/account_profiles/nested-public-parent/nested_profile_groups/parceiros/members'
        );

        $members = $this->getJson(
            "{$this->base_api_tenant}account_profiles/nested-public-parent/nested_profile_groups/parceiros/members",
            $this->getHeaders()
        );
        $members->assertOk();
        $members->assertJsonPath('data.0.id', (string) $partnerB->_id);
        $members->assertJsonPath('data.1.id', (string) $partnerA->_id);
        $this->assertSame(
            ['public-partner-b', 'public-partner-a'],
            collect($members->json('data'))->pluck('slug')->all()
        );
    }

    public function test_public_account_profile_detail_nested_groups_use_public_catalog_eligibility(): void
    {
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'guest_public'],
            ['capabilities' => [
                'is_queryable' => true,
                'is_publicly_navigable' => false,
                'is_publicly_discoverable' => false,
            ]]
        );
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'hidden_guest'],
            ['capabilities' => [
                'is_queryable' => false,
                'is_publicly_navigable' => false,
                'is_publicly_discoverable' => false,
            ]]
        );

        $navigableMember = $this->createNestedProfileFixture(
            'Navigable Member',
            'navigable-member',
            ['profile_type' => 'venue']
        );
        $nonNavigableMember = $this->createNestedProfileFixture(
            'Non Navigable Member',
            'non-navigable-member',
            ['profile_type' => 'guest_public']
        );
        $hiddenMember = $this->createNestedProfileFixture(
            'Hidden Member',
            'hidden-member',
            ['profile_type' => 'hidden_guest']
        );

        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Queryability Contract Parent',
            'slug' => 'queryability-contract-parent',
            'is_active' => true,
            'visibility' => 'public',
        ])->fresh();

        $metadata = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id,
            [
                'aggregate_revision' => max(1, (int) ($parent->aggregate_revision ?? 1)),
                'nested_profile_groups' => [[
                    'id' => 'parceiros',
                    'label' => 'Parceiros',
                    'order' => 0,
                ]],
            ],
            $this->getHeaders()
        );
        $metadata->assertOk();

        $delta = $this->patchJson(
            "{$this->base_tenant_api_admin}account_profiles/".(string) $parent->_id."/nested_profile_groups/parceiros/members",
            [
                'aggregate_revision' => (int) $metadata->json('data.aggregate_revision'),
                'add_ids' => [(string) $navigableMember->_id],
            ],
            $this->getHeaders()
        );
        $delta->assertOk();

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/queryability-contract-parent",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.nested_profile_groups');
        $response->assertJsonPath(
            'data.nested_profile_groups.0.members_path',
            '/api/v1/account_profiles/queryability-contract-parent/nested_profile_groups/parceiros/members'
        );

        $members = $this->getJson(
            "{$this->base_api_tenant}account_profiles/queryability-contract-parent/nested_profile_groups/parceiros/members",
            $this->getHeaders()
        );
        $members->assertOk();
        $members->assertJsonCount(1, 'data');
        $members->assertJsonPath('data.0.slug', 'navigable-member');
        $members->assertJsonPath('data.0.can_open_public_detail', true);
        $members->assertJsonPath('data.0.public_detail_path', '/parceiro/navigable-member');
        $this->assertSame(['navigable-member'], collect($members->json('data'))->pluck('slug')->all());
    }

    public function test_public_account_profile_detail_hides_nested_groups_when_type_capability_is_disabled(): void
    {
        $venueType = TenantProfileType::query()
            ->where('type', 'venue')
            ->firstOrFail();
        $venueType->capabilities = [
            'is_queryable' => true,
            'is_publicly_navigable' => true,
            'is_favoritable' => true,
            'is_publicly_discoverable' => true,
            'is_poi_enabled' => true,
            'has_events' => true,
            'has_nested_profile_groups' => false,
        ];
        $venueType->save();
        $partner = $this->createNestedProfileFixture('Hidden Public Partner', 'hidden-public-partner');
        $parent = AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'venue',
            'display_name' => 'Hidden Nested Public Parent',
            'slug' => 'hidden-nested-public-parent',
            'is_active' => true,
            'visibility' => 'public',
            'nested_profile_groups' => [
                [
                    'id' => 'parceiros',
                    'label' => 'Parceiros',
                    'order' => 0,
                    'account_profile_ids' => [(string) $partner->_id],
                ],
            ],
        ])->fresh();

        $response = $this->getJson(
            "{$this->base_api_tenant}account_profiles/{$parent->slug}",
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data.nested_profile_groups');
    }

    public function test_account_profile_index_filters_by_account(): void
    {
        $otherAccount = Account::create([
            'name' => 'Account B',
            'document' => 'DOC-B',
        ]);

        AccountProfile::create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile A',
            'is_active' => true,
        ]);
        AccountProfile::create([
            'account_id' => (string) $otherAccount->_id,
            'profile_type' => 'personal',
            'display_name' => 'Profile B',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles?account_id=".(string) $this->account->_id,
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $items = collect($response->json('data'));
        $this->assertTrue($items->every(fn (array $item): bool => $item['account_id'] === (string) $this->account->_id));
    }

    public function test_account_profile_index_queryable_only_excludes_non_queryable_profiles(): void
    {
        TenantProfileType::query()->updateOrCreate(
            ['type' => 'hidden_guest'],
            ['capabilities' => [
                'is_queryable' => false,
                'is_publicly_navigable' => false,
                'is_publicly_discoverable' => false,
            ]]
        );

        $this->createNestedProfileFixture('Queryable Profile', 'queryable-profile');
        $hiddenProfile = $this->createNestedProfileFixture(
            'Hidden Profile',
            'hidden-profile',
            ['profile_type' => 'hidden_guest']
        );

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles?queryable_only=1&exclude_account_profile_id=".(string) $hiddenProfile->_id,
            $this->getHeaders()
        );

        $response->assertStatus(200);
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('queryable-profile', $slugs);
        $this->assertNotContains('hidden-profile', $slugs);
    }

    public function test_account_profile_candidates_endpoint_returns_queryable_profiles(): void
    {
        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        TenantProfileType::query()->updateOrCreate(
            ['type' => 'hidden_guest'],
            ['capabilities' => [
                'is_queryable' => false,
                'is_publicly_navigable' => false,
                'is_publicly_discoverable' => false,
            ]]
        );

        $queryable = $this->createNestedProfileFixture('Queryable Candidate', 'queryable-candidate');
        $hidden = $this->createNestedProfileFixture(
            'Hidden Candidate',
            'hidden-candidate',
            [
                'profile_type' => 'hidden_guest',
                'name_search_key' => 'hidden candidate',
            ]
        );
        $queryable->forceFill(['name_search_key' => 'queryable candidate'])->save();

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/candidates?scope=queryable&exclude_account_profile_id=".(string) $hidden->_id
        );

        $response->assertOk();
        $response->assertJsonPath('data.0.id', (string) $queryable->_id);
        $this->assertSame(
            [(string) $queryable->_id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_account_profile_contact_sources_endpoint_returns_only_contact_capable_profiles(): void
    {
        $this->enableContactChannelsCapability('venue');

        $contactOwn = $this->createNestedProfileFixture(
            'Contact Own Candidate',
            'contact-own-candidate',
            [
                'contact_mode' => 'own',
            ]
        );
        $mirrored = $this->createNestedProfileFixture(
            'Mirrored Candidate',
            'mirrored-candidate',
            [
                'contact_mode' => 'mirrored_account_profile',
            ]
        );
        $contactOwn->forceFill(['name_search_key' => 'contact own candidate'])->save();
        $mirrored->forceFill(['name_search_key' => 'mirrored candidate'])->save();

        Sanctum::actingAs(LandlordUser::query()->firstOrFail(), ['account-users:view']);

        $response = $this->getJson(
            "{$this->base_tenant_api_admin}account_profiles/contact_sources?exclude_account_profile_id=".(string) $mirrored->_id
        );

        $response->assertOk();
        $response->assertJsonPath('data.0.id', (string) $contactOwn->_id);
        $this->assertSame(
            [(string) $contactOwn->_id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createNestedProfileFixture(string $name, string $slug, array $overrides = []): AccountProfile
    {
        $account = Account::create([
            'name' => "{$name} Account",
            'document' => 'DOC-'.strtoupper(str_replace('-', '_', $slug)).'-'.uniqid('', true),
        ]);

        return AccountProfile::create(array_merge([
            'account_id' => (string) $account->_id,
            'profile_type' => 'venue',
            'display_name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'visibility' => 'public',
        ], $overrides))->fresh();
    }

    private function enableContactChannelsCapability(string $type): void
    {
        $profileType = TenantProfileType::query()->where('type', $type)->firstOrFail();
        $profileType->capabilities = array_merge(
            is_array($profileType->capabilities ?? null) ? $profileType->capabilities : [],
            ['has_contact_channels' => true],
        );
        $profileType->save();
    }

    private function initializeSystem(): void
    {
        $service = $this->app->make(SystemInitializationService::class);

        $payload = new InitializationPayload(
            landlord: ['name' => 'Landlord HQ'],
            tenant: ['name' => 'Tenant Zeta', 'subdomain' => 'tenant-zeta'],
            role: ['name' => 'Root', 'permissions' => ['*']],
            user: ['name' => 'Root User', 'email' => 'root@example.org', 'password' => 'Secret!234'],
            themeDataSettings: [
                'brightness_default' => 'light',
                'primary_seed_color' => '#fff',
                'secondary_seed_color' => '#000',
            ],
            logoSettings: ['light_logo_uri' => '/logos/light.png'],
            pwaIcon: ['icon192_uri' => '/pwa/icon192.png'],
            tenantDomains: ['tenant-zeta.test']
        );

        $service->initialize($payload);
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $service = $this->app->make(AccountUserService::class);
        $user = $service->create(
            $this->account,
            [
                'name' => 'Account Viewer',
                'email' => uniqid('account-viewer', true).'@example.org',
                'password' => 'Secret!234',
            ],
            (string) $this->accountRoleTemplate->_id
        );

        Sanctum::actingAs($user, $permissions);

        return $user;
    }

    private function assertMediaUrlHealthy(?string $url): void
    {
        $this->assertNotEmpty($url);
        $this->assertStringContainsString(
            "{$this->base_tenant_url}api/v1/media/account-profiles/",
            $url
        );
        $this->assertStringContainsString('v=', $url);

        $this->assertMediaUrlAccess($url, 200);
    }

    private function assertMediaUrlAccess(?string $url, int $expectedStatus): void
    {
        $this->assertNotEmpty($url);

        $canonicalResponse = $this->get($url);
        $canonicalResponse->assertStatus($expectedStatus);
    }

    private function assertMediaStored(string $profileId, string $kind): string
    {
        $tenant = Tenant::current();
        $tenantSlug = $tenant?->slug ?? $this->tenant->subdomain;
        $directory = "tenants/{$tenantSlug}/account_profiles/{$profileId}";
        $files = Storage::disk('public')->files($directory);
        $match = collect($files)->first(
            fn (string $path): bool => str_contains(basename($path), "{$kind}.")
        );
        $this->assertNotEmpty($match);

        return $match;
    }

    private function getMultipartHeaders(): array
    {
        $headers = $this->getHeaders();
        unset($headers['Content-Type']);
        $headers['Accept'] = 'application/json';

        return $headers;
    }
}
