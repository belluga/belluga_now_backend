<?php

declare(strict_types=1);

namespace Tests\Feature\Favorites;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class FavoriteSnapshotProjectionTest extends TestCaseTenant
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

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        AccountProfile::query()->withTrashed()->forceDelete();
        EventOccurrence::query()->withTrashed()->forceDelete();

        DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('favoritable_account_profile_snapshots')
            ->deleteMany([]);

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
        ]);
    }

    public function test_occurrence_crud_refreshes_snapshot_ordering_fields(): void
    {
        $profile = $this->createProfile('Profile Snapshot CRUD', 'profile-snapshot-crud');

        $futureLate = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->addDays(5),
        );
        $futureSoon = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->addDays(2),
        );
        $pastRecent = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->subDay(),
        );

        $snapshot = $this->loadSnapshot((string) $profile->_id);
        $this->assertNotNull($snapshot);
        $this->assertSame((string) $futureSoon->_id, (string) ($snapshot['next_event_occurrence_id'] ?? ''));
        $this->assertNotNull($snapshot['last_event_occurrence_at'] ?? null);

        $futureSoon->forceFill([
            'starts_at' => Carbon::now()->addDays(10),
            'ends_at' => Carbon::now()->addDays(10)->addHours(2),
        ]);
        $futureSoon->save();

        $snapshotAfterUpdate = $this->loadSnapshot((string) $profile->_id);
        $this->assertNotNull($snapshotAfterUpdate);
        $this->assertSame((string) $futureLate->_id, (string) ($snapshotAfterUpdate['next_event_occurrence_id'] ?? ''));

        $pastRecent->delete();

        $snapshotAfterDelete = $this->loadSnapshot((string) $profile->_id);
        $this->assertNotNull($snapshotAfterDelete);
        $this->assertNull($snapshotAfterDelete['last_event_occurrence_at'] ?? null);
    }

    public function test_profile_state_changes_remove_and_rebuild_snapshot(): void
    {
        $profile = $this->createProfile('Profile Snapshot State', 'profile-snapshot-state');

        $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->addDay(),
        );

        $this->assertNotNull($this->loadSnapshot((string) $profile->_id));

        $profile->forceFill([
            'is_active' => false,
        ]);
        $profile->save();

        $this->assertNull($this->loadSnapshot((string) $profile->_id));

        $profile->forceFill([
            'is_active' => true,
        ]);
        $profile->save();

        $this->assertNotNull($this->loadSnapshot((string) $profile->_id));

        $profile->delete();

        $this->assertNull($this->loadSnapshot((string) $profile->_id));
    }

    public function test_snapshot_materializes_visual_preview_and_live_now_fields(): void
    {
        $profile = $this->createProfile(
            displayName: 'Profile Visual Snapshot',
            slug: 'profile-visual-snapshot',
            profileType: 'restaurant',
            avatarUrl: null,
            coverUrl: 'https://cdn.test/profile-cover.png',
        );

        $liveOccurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->subMinutes(20),
            endsAt: Carbon::now()->addMinutes(40),
        );
        $futureOccurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->addHours(6),
            endsAt: Carbon::now()->addHours(8),
        );

        $snapshot = $this->loadSnapshot((string) $profile->_id);

        $this->assertNotNull($snapshot);
        $target = $this->toArray($snapshot['target'] ?? []);
        $this->assertSame('restaurant', (string) ($target['profile_type'] ?? ''));
        $this->assertSame('https://cdn.test/profile-cover.png', $target['cover_url'] ?? null);
        $this->assertFalse((bool) ($target['can_open_public_detail'] ?? true));
        $this->assertArrayHasKey('public_detail_path', $target);
        $this->assertNull($target['public_detail_path']);
        $navigation = $this->toArray($snapshot['navigation'] ?? []);
        $expectedEventPath = '/agenda/evento/event-slug?occurrence='.(string) $liveOccurrence->_id;
        $this->assertSame('event', $navigation['kind'] ?? null);
        $this->assertSame('event-slug', $navigation['target_slug'] ?? null);
        $this->assertSame($expectedEventPath, $navigation['target_path'] ?? null);
        $this->assertFalse((bool) ($navigation['can_open_public_detail'] ?? true));
        $this->assertArrayHasKey('profile_target_path', $navigation);
        $this->assertNull($navigation['profile_target_path']);
        $this->assertSame($expectedEventPath, $navigation['event_target_path'] ?? null);
        $this->assertSame('event-slug', $navigation['event_target_slug'] ?? null);
        $this->assertSame((string) $liveOccurrence->_id, (string) ($navigation['event_occurrence_id'] ?? ''));
        $this->assertSame((string) $liveOccurrence->_id, (string) ($snapshot['live_now_event_occurrence_id'] ?? ''));
        $this->assertSame((string) $futureOccurrence->_id, (string) ($snapshot['next_event_occurrence_id'] ?? ''));
        $this->assertNotNull($snapshot['live_now_event_occurrence_at'] ?? null);
        $this->assertNotNull($snapshot['next_event_occurrence_at'] ?? null);
    }

    public function test_snapshot_rebuilds_immediately_without_a_queue_worker(): void
    {
        config([
            'queue.default' => 'mongodb',
            'queue.connections.mongodb.connection' => 'mongodb',
            'queue.connections.mongodb.collection' => 'jobs',
        ]);

        $profile = $this->createProfile(
            displayName: 'Profile Immediate Snapshot',
            slug: 'profile-immediate-snapshot',
        );

        $liveOccurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->subMinutes(10),
            endsAt: Carbon::now()->addMinutes(50),
        );
        $futureOccurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->addHours(4),
            endsAt: Carbon::now()->addHours(6),
        );

        $snapshot = $this->loadSnapshot((string) $profile->_id);

        $this->assertNotNull($snapshot);
        $this->assertSame((string) $liveOccurrence->_id, (string) ($snapshot['live_now_event_occurrence_id'] ?? ''));
        $this->assertSame((string) $futureOccurrence->_id, (string) ($snapshot['next_event_occurrence_id'] ?? ''));
    }

    public function test_snapshot_rebuild_clears_live_now_but_preserves_next_event_for_home_ordering(): void
    {
        $profile = $this->createProfile(
            displayName: 'Profile Live Snapshot',
            slug: 'profile-live-snapshot',
        );

        $liveOccurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->subMinutes(20),
            endsAt: Carbon::now()->addMinutes(40),
        );
        $futureOccurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->addHours(6),
            endsAt: Carbon::now()->addHours(8),
        );

        $snapshot = $this->loadSnapshot((string) $profile->_id);
        $this->assertNotNull($snapshot);
        $this->assertSame((string) $liveOccurrence->_id, (string) ($snapshot['live_now_event_occurrence_id'] ?? ''));
        $this->assertSame((string) $futureOccurrence->_id, (string) ($snapshot['next_event_occurrence_id'] ?? ''));

        $liveOccurrence->forceFill([
            'starts_at' => Carbon::now()->subHours(4),
            'ends_at' => Carbon::now()->subHours(2),
            'effective_ends_at' => Carbon::now()->subHours(2),
        ]);
        $liveOccurrence->save();

        $snapshotAfterUpdate = $this->loadSnapshot((string) $profile->_id);
        $this->assertNotNull($snapshotAfterUpdate);
        $this->assertNull($snapshotAfterUpdate['live_now_event_occurrence_id'] ?? null);
        $this->assertNull($snapshotAfterUpdate['live_now_event_occurrence_at'] ?? null);
        $this->assertSame((string) $futureOccurrence->_id, (string) ($snapshotAfterUpdate['next_event_occurrence_id'] ?? ''));
        $this->assertNotNull($snapshotAfterUpdate['next_event_occurrence_at'] ?? null);
    }

    public function test_snapshot_materializes_public_navigation_contract_from_profile_type_capabilities(): void
    {
        $publicProfile = $this->createProfile(
            displayName: 'Profile Public Snapshot',
            slug: 'profile-public-snapshot',
            profileType: 'artist',
        );
        $hiddenProfile = $this->createProfile(
            displayName: 'Profile Hidden Snapshot',
            slug: 'profile-hidden-snapshot',
            profileType: 'personal',
        );

        $publicOccurrence = $this->createOccurrence(
            profileId: (string) $publicProfile->_id,
            startsAt: Carbon::now()->addDay(),
        );
        $hiddenOccurrence = $this->createOccurrence(
            profileId: (string) $hiddenProfile->_id,
            startsAt: Carbon::now()->addDays(2),
        );

        $publicSnapshot = $this->loadSnapshot((string) $publicProfile->_id);
        $hiddenSnapshot = $this->loadSnapshot((string) $hiddenProfile->_id);

        $this->assertNotNull($publicSnapshot);
        $this->assertNotNull($hiddenSnapshot);

        $publicTarget = $this->toArray($publicSnapshot['target'] ?? []);
        $publicNavigation = $this->toArray($publicSnapshot['navigation'] ?? []);
        $this->assertTrue((bool) ($publicTarget['can_open_public_detail'] ?? false));
        $this->assertSame('/parceiro/profile-public-snapshot', $publicTarget['public_detail_path'] ?? null);
        $this->assertSame('event', $publicNavigation['kind'] ?? null);
        $this->assertTrue((bool) ($publicNavigation['can_open_public_detail'] ?? false));
        $this->assertSame(
            '/agenda/evento/event-slug?occurrence='.(string) $publicOccurrence->_id,
            $publicNavigation['target_path'] ?? null,
        );
        $this->assertSame('/parceiro/profile-public-snapshot', $publicNavigation['profile_target_path'] ?? null);
        $this->assertSame(
            '/agenda/evento/event-slug?occurrence='.(string) $publicOccurrence->_id,
            $publicNavigation['event_target_path'] ?? null,
        );
        $this->assertSame((string) $publicOccurrence->_id, (string) ($publicNavigation['event_occurrence_id'] ?? ''));

        $hiddenTarget = $this->toArray($hiddenSnapshot['target'] ?? []);
        $hiddenNavigation = $this->toArray($hiddenSnapshot['navigation'] ?? []);
        $this->assertFalse((bool) ($hiddenTarget['can_open_public_detail'] ?? true));
        $this->assertArrayHasKey('public_detail_path', $hiddenTarget);
        $this->assertNull($hiddenTarget['public_detail_path']);
        $this->assertSame('event', $hiddenNavigation['kind'] ?? null);
        $this->assertFalse((bool) ($hiddenNavigation['can_open_public_detail'] ?? true));
        $this->assertSame(
            '/agenda/evento/event-slug?occurrence='.(string) $hiddenOccurrence->_id,
            $hiddenNavigation['target_path'] ?? null,
        );
        $this->assertArrayHasKey('profile_target_path', $hiddenNavigation);
        $this->assertNull($hiddenNavigation['profile_target_path']);
        $this->assertSame(
            '/agenda/evento/event-slug?occurrence='.(string) $hiddenOccurrence->_id,
            $hiddenNavigation['event_target_path'] ?? null,
        );
        $this->assertSame((string) $hiddenOccurrence->_id, (string) ($hiddenNavigation['event_occurrence_id'] ?? ''));
    }

    public function test_snapshot_ignores_legacy_linked_account_profiles_projection_without_canonical_profile_association(): void
    {
        $profile = $this->createProfile(
            displayName: 'Profile Linked Snapshot',
            slug: 'profile-linked-snapshot',
        );

        $this->createOccurrence(
            profileId: 'legacy-venue-placeholder',
            startsAt: Carbon::now()->addHour(),
            endsAt: Carbon::now()->addHours(2),
            linkedAccountProfiles: [
                [
                    'id' => (string) $profile->_id,
                    'display_name' => (string) $profile->display_name,
                    'slug' => (string) $profile->slug,
                    'profile_type' => (string) $profile->profile_type,
                    'avatar_url' => $profile->avatar_url ?? null,
                    'cover_url' => $profile->cover_url ?? null,
                ],
            ],
            includeVenue: false,
        );

        $snapshot = $this->loadSnapshot((string) $profile->_id);

        $this->assertNotNull($snapshot);
        $this->assertSame('account_profile', data_get($snapshot, 'navigation.kind'));
        $this->assertNull(data_get($snapshot, 'navigation.event_target_path'));
        $this->assertNull(data_get($snapshot, 'next_event_occurrence_id'));
        $this->assertNull(data_get($snapshot, 'live_now_event_occurrence_id'));
    }

    public function test_snapshot_rebuilds_from_canonical_place_ref_with_nested_id_shape(): void
    {
        $profile = $this->createProfile(
            displayName: 'Profile Place Ref Snapshot',
            slug: 'profile-place-ref-snapshot',
            profileType: 'restaurant',
        );

        $occurrence = $this->createOccurrence(
            profileId: 'legacy-venue-placeholder',
            startsAt: Carbon::now()->addHour(),
            endsAt: Carbon::now()->addHours(2),
            includeVenue: false,
            placeRef: [
                'type' => 'account_profile',
                '_id' => (string) $profile->_id,
            ],
        );

        $snapshot = $this->loadSnapshot((string) $profile->_id);

        $this->assertNotNull($snapshot);
        $this->assertSame((string) $profile->_id, (string) data_get($snapshot, 'target.id'));
        $this->assertSame(
            '/agenda/evento/event-slug?occurrence='.(string) $occurrence->_id,
            data_get($snapshot, 'navigation.target_path'),
        );
    }

    public function test_snapshot_rebuilds_from_canonical_event_parties_without_linked_profiles_or_artists(): void
    {
        $profile = $this->createProfile(
            displayName: 'Profile Event Party Snapshot',
            slug: 'profile-event-party-snapshot',
        );

        $occurrence = $this->createOccurrence(
            profileId: 'legacy-venue-placeholder',
            startsAt: Carbon::now()->addHour(),
            endsAt: Carbon::now()->addHours(2),
            includeVenue: false,
            eventParties: [
                [
                    'party_type' => 'artist',
                    'party_ref_id' => (string) $profile->_id,
                    'metadata' => [
                        'display_name' => (string) $profile->display_name,
                        'slug' => (string) $profile->slug,
                        'profile_type' => (string) $profile->profile_type,
                    ],
                ],
            ],
        );

        $snapshot = $this->loadSnapshot((string) $profile->_id);

        $this->assertNotNull($snapshot);
        $this->assertSame((string) $profile->_id, (string) data_get($snapshot, 'target.id'));
        $this->assertSame(
            '/agenda/evento/event-slug?occurrence='.(string) $occurrence->_id,
            data_get($snapshot, 'navigation.target_path'),
        );
    }

    public function test_snapshot_ignores_legacy_artists_projection_without_canonical_profile_association(): void
    {
        $profile = $this->createProfile(
            displayName: 'Profile Legacy Artists Snapshot',
            slug: 'profile-legacy-artists-snapshot',
        );

        $this->createOccurrence(
            profileId: 'legacy-venue-placeholder',
            startsAt: Carbon::now()->addHour(),
            endsAt: Carbon::now()->addHours(2),
            includeVenue: false,
            artists: [
                [
                    'id' => (string) $profile->_id,
                    'display_name' => (string) $profile->display_name,
                    'slug' => (string) $profile->slug,
                    'profile_type' => (string) $profile->profile_type,
                ],
            ],
        );

        $snapshot = $this->loadSnapshot((string) $profile->_id);

        $this->assertNotNull($snapshot);
        $this->assertSame('account_profile', data_get($snapshot, 'navigation.kind'));
        $this->assertNull(data_get($snapshot, 'navigation.event_target_path'));
        $this->assertNull(data_get($snapshot, 'next_event_occurrence_id'));
        $this->assertNull(data_get($snapshot, 'live_now_event_occurrence_id'));
    }

    private function createProfile(
        string $displayName,
        string $slug,
        string $profileType = 'artist',
        ?string $avatarUrl = null,
        ?string $coverUrl = null,
    ): AccountProfile
    {
        [$account] = $this->seedAccountWithRole([
            'account-users:view',
        ]);

        return AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => $profileType,
            'display_name' => $displayName,
            'slug' => $slug,
            'is_active' => true,
            'is_verified' => false,
            'avatar_url' => $avatarUrl,
            'cover_url' => $coverUrl,
        ]);
    }

    private function createOccurrence(
        string $profileId,
        Carbon $startsAt,
        ?Carbon $endsAt = null,
        array $linkedAccountProfiles = [],
        bool $includeVenue = true,
        ?array $placeRef = null,
        array $eventParties = [],
        array $artists = [],
        ?array $venue = null,
    ): EventOccurrence
    {
        $eventId = 'event-'.uniqid('', true);
        $occurrenceSlug = str_replace('.', '-', $eventId).'-occ-1';
        $resolvedEndsAt = $endsAt ?? $startsAt->copy()->addHours(2);
        $resolvedPlaceRef = $placeRef ?? ($includeVenue ? [
            'type' => 'account_profile',
            'id' => $profileId,
        ] : []);
        $resolvedVenue = $venue ?? ($includeVenue ? [
            'id' => $profileId,
            'display_name' => 'Profile Venue',
        ] : []);

        return EventOccurrence::query()->create([
            'event_id' => $eventId,
            'slug' => 'event-slug',
            'occurrence_slug' => $occurrenceSlug,
            'title' => 'Occurrence Snapshot',
            'content' => 'Occurrence content',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0, -20.0],
                ],
            ],
            'place_ref' => $resolvedPlaceRef,
            'venue' => $resolvedVenue,
            'event_parties' => $eventParties,
            'linked_account_profiles' => $linkedAccountProfiles,
            'artists' => $artists,
            'publication' => [
                'status' => 'published',
                'publish_at' => Carbon::now()->subMinute(),
            ],
            'is_event_published' => true,
            'is_active' => true,
            'starts_at' => $startsAt,
            'ends_at' => $resolvedEndsAt,
            'effective_ends_at' => $resolvedEndsAt,
            'deleted_at' => null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSnapshot(string $profileId): ?array
    {
        $snapshot = DB::connection('tenant')
            ->getDatabase()
            ->selectCollection('favoritable_account_profile_snapshots')
            ->findOne([
                'registry_key' => 'account_profile',
                'target_type' => 'account_profile',
                'target_id' => $profileId,
            ]);

        if ($snapshot === null) {
            return null;
        }

        return $this->toArray($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
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

        if (is_object($value)) {
            return (array) $value;
        }

        return [];
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

        $tenant = Tenant::query()->first();
        if ($tenant) {
            $this->landlord->tenant_primary->slug = $tenant->slug;
            $this->landlord->tenant_primary->subdomain = $tenant->subdomain;
            $this->landlord->tenant_primary->id = (string) $tenant->_id;
            $this->landlord->tenant_primary->role_admin->id = (string) ($tenant->roleTemplates()->first()?->_id ?? '');
        }
    }
}
