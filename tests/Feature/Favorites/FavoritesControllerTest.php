<?php

declare(strict_types=1);

namespace Tests\Feature\Favorites;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Application\Push\PushChannelNamingService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Favorites\Models\Tenants\FavoriteEdge;
use Belluga\PushHandler\Contracts\PushTopicTransportContract;
use Belluga\PushHandler\Models\Tenants\PushCredential;
use Belluga\PushHandler\Models\Tenants\PushDevice;
use Belluga\PushHandler\Models\Tenants\TenantPushSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakePushTopicTransport;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class FavoritesControllerTest extends TestCaseTenant
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

    private AccountUserService $userService;

    private AccountUser $user;

    private FakePushTopicTransport $topicTransport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        Carbon::setTestNow(Carbon::parse('2026-03-20T12:00:00Z'));

        if (! self::$bootstrapped) {
            $this->refreshLandlordAndTenantDatabases();
            $this->initializeSystem();
            self::$bootstrapped = true;
        }

        $tenant = Tenant::query()->where('slug', $this->tenant->slug)->firstOrFail();
        $tenant->makeCurrent();

        FavoriteEdge::query()->delete();
        AccountProfile::query()->withTrashed()->forceDelete();
        EventOccurrence::query()->withTrashed()->forceDelete();

        [$this->account] = $this->seedAccountWithRole([
            'account-users:view',
            'account-users:create',
            'account-users:update',
            'account-users:delete',
        ]);

        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser(['account-users:view']);
        $this->topicTransport = new FakePushTopicTransport;
        $this->app->instance(PushTopicTransportContract::class, $this->topicTransport);

        Sanctum::actingAs($this->user, ['account-users:view']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_favorites_orders_live_now_then_upcoming_then_fallback_by_favorited_at(): void
    {
        $profileLiveNow = $this->createProfile('Profile Live Now', 'profile-live-now');
        $profileUpcomingSoon = $this->createProfile('Profile Upcoming Soon', 'profile-upcoming-soon');
        $profileUpcomingLater = $this->createProfile('Profile Upcoming Later', 'profile-upcoming-later');
        $profilePastOnly = $this->createProfile('Profile Past Only', 'profile-past-only');
        $profileNoEvent = $this->createProfile('Profile No Event', 'profile-no-event');

        $this->createOccurrence(
            profileId: (string) $profileLiveNow->_id,
            startsAt: Carbon::now()->copy()->subMinutes(30),
            endsAt: Carbon::now()->copy()->addMinutes(45),
            eventSlug: 'event-live-now',
        );
        $this->createOccurrence(
            profileId: (string) $profileUpcomingSoon->_id,
            startsAt: Carbon::now()->copy()->addDay(),
            eventSlug: 'event-upcoming-soon',
        );
        $this->createOccurrence(
            profileId: (string) $profileUpcomingLater->_id,
            startsAt: Carbon::now()->copy()->addDays(4),
            eventSlug: 'event-upcoming-later',
        );
        $this->createOccurrence(
            profileId: (string) $profilePastOnly->_id,
            startsAt: Carbon::now()->copy()->subDays(2),
            endsAt: Carbon::now()->copy()->subDays(2)->addHours(2),
            eventSlug: 'event-past-only',
        );

        $this->createEdge((string) $profileLiveNow->_id, Carbon::parse('2026-03-10T12:00:00Z'));
        $this->createEdge((string) $profileUpcomingSoon->_id, Carbon::parse('2026-03-11T12:00:00Z'));
        $this->createEdge((string) $profileUpcomingLater->_id, Carbon::parse('2026-03-12T12:00:00Z'));
        $this->createEdge((string) $profilePastOnly->_id, Carbon::parse('2026-03-13T12:00:00Z'));
        $this->createEdge((string) $profileNoEvent->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10&registry_key=account_profile&target_type=account_profile");

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);

        $items = $response->json('items');
        $this->assertCount(5, $items);

        $this->assertSame((string) $profileLiveNow->_id, (string) ($items[0]['target_id'] ?? ''));
        $this->assertSame((string) $profileUpcomingSoon->_id, (string) ($items[1]['target_id'] ?? ''));
        $this->assertSame((string) $profileUpcomingLater->_id, (string) ($items[2]['target_id'] ?? ''));
        $this->assertSame((string) $profileNoEvent->_id, (string) ($items[3]['target_id'] ?? ''));
        $this->assertSame((string) $profilePastOnly->_id, (string) ($items[4]['target_id'] ?? ''));
    }

    public function test_favorites_paginates_without_duplicate_or_order_drift_across_page_boundary(): void
    {
        $profiles = [];
        for ($index = 0; $index < 12; $index++) {
            $profile = $this->createProfile(
                displayName: 'Profile Page '.($index + 1),
                slug: 'profile-page-'.($index + 1),
            );
            $profiles[] = $profile;
            $this->createEdge(
                (string) $profile->_id,
                Carbon::parse('2026-03-20T12:00:00Z')->subMinutes($index),
            );
        }

        $pageOne = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10&registry_key=account_profile&target_type=account_profile");
        $pageTwo = $this->getJson("{$this->base_api_tenant}favorites?page=2&page_size=10&registry_key=account_profile&target_type=account_profile");

        $pageOne->assertStatus(200);
        $pageOne->assertJsonPath('has_more', true);
        $pageTwo->assertStatus(200);
        $pageTwo->assertJsonPath('has_more', false);

        $pageOneItems = $pageOne->json('items');
        $pageTwoItems = $pageTwo->json('items');

        $this->assertCount(10, $pageOneItems);
        $this->assertCount(2, $pageTwoItems);

        $orderedIds = array_map(
            static fn (AccountProfile $profile): string => (string) $profile->_id,
            $profiles,
        );

        $this->assertSame(
            array_slice($orderedIds, 0, 10),
            array_map(static fn (array $item): string => (string) ($item['target_id'] ?? ''), $pageOneItems),
        );
        $this->assertSame(
            array_slice($orderedIds, 10),
            array_map(static fn (array $item): string => (string) ($item['target_id'] ?? ''), $pageTwoItems),
        );
        $this->assertSame(
            [],
            array_values(array_intersect(
                array_map(static fn (array $item): string => (string) ($item['target_id'] ?? ''), $pageOneItems),
                array_map(static fn (array $item): string => (string) ($item['target_id'] ?? ''), $pageTwoItems),
            )),
        );
    }

    public function test_favorites_returns_empty_payload_when_user_has_no_edges(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10");

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);
        $this->assertSame([], $response->json('items'));
    }

    public function test_favorites_uses_default_registry_when_registry_filter_is_omitted(): void
    {
        $profile = $this->createProfile('Profile Default Registry', 'profile-default-registry');
        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10");

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame((string) $profile->_id, (string) ($items[0]['target_id'] ?? ''));
        $this->assertSame('account_profile', (string) ($items[0]['registry_key'] ?? ''));
        $response->assertJsonPath('items.0.target.can_open_public_detail', true);
        $response->assertJsonPath('items.0.target.public_detail_path', '/parceiro/profile-default-registry');
        $response->assertJsonPath('items.0.navigation.kind', 'account_profile');
        $response->assertJsonPath('items.0.navigation.can_open_public_detail', true);
        $response->assertJsonPath('items.0.navigation.target_path', '/parceiro/profile-default-registry');
        $response->assertJsonPath('items.0.navigation.profile_target_path', '/parceiro/profile-default-registry');
        $response->assertJsonPath('items.0.navigation.event_target_path', null);
    }

    public function test_favorites_prefers_canonical_event_navigation_target_when_future_event_exists(): void
    {
        $profile = $this->createProfile('Profile Event Preferred', 'profile-event-preferred');
        $occurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->copy()->addDays(2),
            eventSlug: 'event-preferred-slug',
        );

        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10&registry_key=account_profile&target_type=account_profile");

        $response->assertStatus(200);
        $response->assertJsonPath('items.0.target.public_detail_path', '/parceiro/profile-event-preferred');
        $response->assertJsonPath('items.0.navigation.kind', 'event');
        $response->assertJsonPath('items.0.navigation.target_slug', 'event-preferred-slug');
        $response->assertJsonPath('items.0.navigation.target_path', '/agenda/evento/event-preferred-slug?occurrence='.(string) $occurrence->_id);
        $response->assertJsonPath('items.0.navigation.profile_target_path', '/parceiro/profile-event-preferred');
        $response->assertJsonPath('items.0.navigation.event_target_path', '/agenda/evento/event-preferred-slug?occurrence='.(string) $occurrence->_id);
        $response->assertJsonPath('items.0.navigation.event_target_slug', 'event-preferred-slug');
        $response->assertJsonPath('items.0.navigation.event_occurrence_id', (string) $occurrence->_id);
        $response->assertJsonPath('items.0.navigation.can_open_public_detail', true);
    }

    public function test_favorites_returns_edges_for_anonymous_identity(): void
    {
        $profile = $this->createProfile('Profile Anonymous', 'profile-anonymous');
        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $this->user->setAttribute('identity_state', 'anonymous');
        $this->user->save();
        Sanctum::actingAs($this->user, ['account-users:view']);

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10");

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame((string) $profile->_id, (string) ($items[0]['target_id'] ?? ''));
    }

    public function test_favorites_exposes_account_profile_visual_preview_and_live_occurrence_state_fields(): void
    {
        $profile = $this->createProfile(
            displayName: 'Profile Visual Payload',
            slug: 'profile-visual-payload',
            profileType: 'restaurant',
            avatarUrl: null,
            coverUrl: 'https://cdn.test/profile-cover.png',
        );

        $liveOccurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->copy()->subMinutes(20),
            endsAt: Carbon::now()->copy()->addMinutes(40),
            eventSlug: 'event-visual-payload',
        );
        $futureOccurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->copy()->addHours(6),
            endsAt: Carbon::now()->copy()->addHours(8),
            eventSlug: 'event-visual-payload-future',
        );
        $pastOccurrence = $this->createOccurrence(
            profileId: (string) $profile->_id,
            startsAt: Carbon::now()->copy()->subDays(2),
            endsAt: Carbon::now()->copy()->subDays(2)->addHours(2),
            eventSlug: 'event-visual-payload-past',
        );

        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10&registry_key=account_profile&target_type=account_profile");

        $response->assertStatus(200);
        $response->assertJsonPath('items.0.target.cover_url', 'https://cdn.test/profile-cover.png');
        $response->assertJsonPath('items.0.target.profile_type', 'restaurant');
        $response->assertJsonPath('items.0.target.can_open_public_detail', false);
        $response->assertJsonPath('items.0.target.public_detail_path', null);
        $response->assertJsonPath('items.0.navigation.kind', 'event');
        $response->assertJsonPath('items.0.navigation.can_open_public_detail', false);
        $response->assertJsonPath('items.0.navigation.target_slug', 'event-visual-payload');
        $response->assertJsonPath('items.0.navigation.target_path', '/agenda/evento/event-visual-payload?occurrence='.(string) $liveOccurrence->_id);
        $response->assertJsonPath('items.0.navigation.profile_target_path', null);
        $response->assertJsonPath('items.0.navigation.event_target_path', '/agenda/evento/event-visual-payload?occurrence='.(string) $liveOccurrence->_id);
        $response->assertJsonPath('items.0.navigation.event_target_slug', 'event-visual-payload');
        $response->assertJsonPath('items.0.navigation.event_occurrence_id', (string) $liveOccurrence->_id);
        $response->assertJsonPath('items.0.occurrence_state.live_now_event_occurrence_id', (string) $liveOccurrence->_id);
        $response->assertJsonPath('items.0.occurrence_state.next_event_occurrence_id', (string) $futureOccurrence->_id);
        $response->assertJsonPath('items.0.occurrence_state.last_event_occurrence_at', $pastOccurrence->starts_at->format(DATE_ATOM));
    }

    public function test_favorites_use_canonical_association_fields_and_ignore_legacy_only_relationships(): void
    {
        $profilePlaceRefObjectId = $this->createProfile('Profile PlaceRef ObjectId', 'profile-place-ref-objectid');
        $profileEventParty = $this->createProfile('Profile Event Party', 'profile-event-party');
        $profileLegacyOnly = $this->createProfile('Profile Legacy Only', 'profile-legacy-only');

        $placeRefOccurrence = $this->createOccurrence(
            profileId: (string) $profilePlaceRefObjectId->_id,
            startsAt: Carbon::now()->copy()->addDay(),
            includeVenue: false,
            placeRef: [
                'type' => 'account_profile',
                '_id' => (string) $profilePlaceRefObjectId->_id,
            ],
            eventSlug: 'event-place-ref-objectid',
        );
        $eventPartyOccurrence = $this->createOccurrence(
            profileId: (string) $profileEventParty->_id,
            startsAt: Carbon::now()->copy()->addDays(2),
            includeVenue: false,
            placeRef: [],
            eventParties: [
                [
                    'party_ref_id' => (string) $profileEventParty->_id,
                ],
            ],
            eventSlug: 'event-party-ref-id',
        );
        $this->createOccurrence(
            profileId: (string) $profileLegacyOnly->_id,
            startsAt: Carbon::now()->copy()->addDays(3),
            includeVenue: false,
            placeRef: [],
            linkedAccountProfiles: [
                [
                    'id' => (string) $profileLegacyOnly->_id,
                ],
            ],
            artists: [
                [
                    'artist_ref_id' => (string) $profileLegacyOnly->_id,
                ],
            ],
            eventSlug: 'event-legacy-only',
        );

        $this->createEdge((string) $profilePlaceRefObjectId->_id, Carbon::parse('2026-03-19T12:00:00Z'));
        $this->createEdge((string) $profileEventParty->_id, Carbon::parse('2026-03-18T12:00:00Z'));
        $this->createEdge((string) $profileLegacyOnly->_id, Carbon::parse('2026-03-17T12:00:00Z'));

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10&registry_key=account_profile&target_type=account_profile");

        $response->assertStatus(200);
        $itemsByTargetId = collect($response->json('items'))->keyBy('target_id');

        $this->assertSame(
            '/agenda/evento/event-place-ref-objectid?occurrence='.(string) $placeRefOccurrence->_id,
            data_get($itemsByTargetId, (string) $profilePlaceRefObjectId->_id.'.navigation.event_target_path'),
        );
        $this->assertSame(
            '/agenda/evento/event-party-ref-id?occurrence='.(string) $eventPartyOccurrence->_id,
            data_get($itemsByTargetId, (string) $profileEventParty->_id.'.navigation.event_target_path'),
        );
        $this->assertNull(
            data_get($itemsByTargetId, (string) $profileLegacyOnly->_id.'.navigation.event_target_path'),
        );
        $this->assertNull(
            data_get($itemsByTargetId, (string) $profileLegacyOnly->_id.'.occurrence_state.next_event_occurrence_at'),
        );
    }

    public function test_favorites_filters_out_inactive_profiles_without_snapshot_fallback(): void
    {
        $profile = $this->createProfile(
            displayName: 'Profile Inactive',
            slug: 'profile-inactive',
            isActive: false,
        );

        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=10");

        $response->assertStatus(200);
        $response->assertJsonPath('has_more', false);
        $this->assertSame([], $response->json('items'));
    }

    public function test_favorites_rejects_page_size_above_ten(): void
    {
        $response = $this->getJson("{$this->base_api_tenant}favorites?page=1&page_size=11");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['page_size']);
    }

    public function test_favorites_store_creates_edge_for_authenticated_identity(): void
    {
        $profile = $this->createProfile('Profile Store', 'profile-store');

        $response = $this->postJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('is_favorite', true);
        $response->assertJsonPath('target_id', (string) $profile->_id);
        $response->assertJsonPath('registry_key', 'account_profile');
        $response->assertJsonPath('target_type', 'account_profile');

        $this->assertTrue(
            FavoriteEdge::query()
                ->where('owner_user_id', (string) $this->user->getAuthIdentifier())
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', (string) $profile->_id)
                ->exists()
        );
    }

    public function test_favorites_destroy_removes_existing_edge(): void
    {
        $profile = $this->createProfile('Profile Destroy', 'profile-destroy');
        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $response = $this->deleteJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('is_favorite', false);
        $response->assertJsonPath('target_id', (string) $profile->_id);

        $this->assertFalse(
            FavoriteEdge::query()
                ->where('owner_user_id', (string) $this->user->getAuthIdentifier())
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', (string) $profile->_id)
                ->exists()
        );
    }

    public function test_favorites_store_and_destroy_sync_account_profile_topic_membership_for_active_push_devices(): void
    {
        $this->seedPushRuntimeReady();
        $this->registerActivePushToken($this->user, 'favorite-topic-token');

        $profile = $this->createProfile('Profile Topic', 'profile-topic');
        $expectedTopic = $this->app->make(PushChannelNamingService::class)
            ->favoriteAccountProfileTopic((string) $profile->_id);

        $this->postJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ])->assertStatus(200);

        $this->assertContains([
            'topic' => $expectedTopic,
            'tokens' => ['favorite-topic-token'],
        ], $this->topicTransport->subscriptions);

        $this->deleteJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ])->assertStatus(200);

        $this->assertContains([
            'topic' => $expectedTopic,
            'tokens' => ['favorite-topic-token'],
        ], $this->topicTransport->unsubscriptions);
    }

    public function test_favorites_store_creates_edge_for_anonymous_identity(): void
    {
        $profile = $this->createProfile('Profile Anonymous Store', 'profile-anonymous-store');

        $this->user->setAttribute('identity_state', 'anonymous');
        $this->user->save();
        Sanctum::actingAs($this->user, ['account-users:view']);

        $response = $this->postJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('is_favorite', true);
        $response->assertJsonPath('target_id', (string) $profile->_id);

        $this->assertTrue(
            FavoriteEdge::query()
                ->where('owner_user_id', (string) $this->user->getAuthIdentifier())
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', (string) $profile->_id)
                ->exists()
        );
    }

    public function test_favorites_destroy_removes_edge_for_anonymous_identity(): void
    {
        $profile = $this->createProfile('Profile Anonymous Destroy', 'profile-anonymous-destroy');
        $this->createEdge((string) $profile->_id, Carbon::parse('2026-03-19T12:00:00Z'));

        $this->user->setAttribute('identity_state', 'anonymous');
        $this->user->save();
        Sanctum::actingAs($this->user, ['account-users:view']);

        $response = $this->deleteJson("{$this->base_api_tenant}favorites", [
            'target_id' => (string) $profile->_id,
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('is_favorite', false);
        $response->assertJsonPath('target_id', (string) $profile->_id);

        $this->assertFalse(
            FavoriteEdge::query()
                ->where('owner_user_id', (string) $this->user->getAuthIdentifier())
                ->where('registry_key', 'account_profile')
                ->where('target_type', 'account_profile')
                ->where('target_id', (string) $profile->_id)
                ->exists()
        );
    }

    private function createAccountUser(array $permissions): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Favorites Role',
            'permissions' => $permissions,
        ]);

        return $this->userService->create($this->account, [
            'name' => 'Favorites User',
            'email' => uniqid('favorites-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function createProfile(
        string $displayName,
        string $slug,
        string $profileType = 'artist',
        ?string $avatarUrl = null,
        ?string $coverUrl = null,
        bool $isActive = true,
    ): AccountProfile {
        [$account] = $this->seedAccountWithRole([
            'account-users:view',
        ]);

        return AccountProfile::query()->create([
            'account_id' => (string) $account->_id,
            'profile_type' => $profileType,
            'display_name' => $displayName,
            'slug' => $slug,
            'is_active' => $isActive,
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
        ?string $eventSlug = null,
    ): EventOccurrence {
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
            'slug' => $eventSlug ?? 'event-slug',
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

    private function createEdge(string $targetId, Carbon $favoritedAt): void
    {
        FavoriteEdge::query()->create([
            'owner_user_id' => (string) $this->user->getAuthIdentifier(),
            'registry_key' => 'account_profile',
            'target_type' => 'account_profile',
            'target_id' => $targetId,
            'favorited_at' => $favoritedAt,
        ]);
    }

    private function seedPushRuntimeReady(): void
    {
        PushCredential::query()->delete();
        TenantPushSettings::query()->delete();

        PushCredential::create([
            'project_id' => 'project-id',
            'client_email' => 'client@example.org',
            'private_key' => 'secret',
        ]);

        TenantPushSettings::create([
            'firebase' => [
                'apiKey' => 'key',
                'androidAppId' => 'android-app',
                'iosAppId' => 'ios-app',
                'projectId' => 'project',
                'messagingSenderId' => 'sender',
                'storageBucket' => 'bucket',
            ],
            'push' => [
                'enabled' => true,
                'max_ttl_days' => 30,
            ],
        ]);
    }

    private function registerActivePushToken(AccountUser $user, string $pushToken): void
    {
        PushDevice::query()->create([
            'tenant_id' => (string) (Tenant::current()?->_id ?? Tenant::current()?->id ?? ''),
            'account_user_id' => (string) $user->_id,
            'account_ids' => $user->getAccessToIds(),
            'device_id' => 'device-'.Str::random(6),
            'platform' => 'android',
            'push_token' => $pushToken,
            'is_active' => true,
            'last_registered_at' => Carbon::now(),
        ]);
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
