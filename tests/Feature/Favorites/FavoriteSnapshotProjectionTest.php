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
        $this->assertSame((string) $liveOccurrence->_id, (string) ($snapshot['live_now_event_occurrence_id'] ?? ''));
        $this->assertSame((string) $futureOccurrence->_id, (string) ($snapshot['next_event_occurrence_id'] ?? ''));
    }

    public function test_snapshot_rebuilds_from_linked_account_profiles_without_artists_or_venue(): void
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
        $this->assertSame((string) $profile->_id, (string) data_get($snapshot, 'target.id'));
        $this->assertSame((string) $profile->slug, (string) data_get($snapshot, 'target.slug'));
    }

    private function createProfile(
        string $displayName,
        string $slug,
        string $profileType = 'artist',
        ?string $avatarUrl = null,
        ?string $coverUrl = null,
    ): AccountProfile
    {
        return AccountProfile::query()->create([
            'account_id' => (string) $this->account->_id,
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
    ): EventOccurrence
    {
        $eventId = 'event-'.uniqid('', true);
        $occurrenceSlug = str_replace('.', '-', $eventId).'-occ-1';
        $resolvedEndsAt = $endsAt ?? $startsAt->copy()->addHours(2);

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
            'venue' => $includeVenue ? [
                'id' => $profileId,
                'display_name' => 'Profile Venue',
            ] : [],
            'linked_account_profiles' => $linkedAccountProfiles,
            'artists' => [],
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
