<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Accounts\AccountUserService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\AttendanceCommitment;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class EventAttendanceControllerTest extends TestCaseTenant
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

        AttendanceCommitment::query()->delete();
        InviteEdge::query()->delete();
        EventOccurrence::query()->delete();
        Event::query()->delete();

        [$this->account] = $this->seedAccountWithRole(['*']);
        $this->userService = $this->app->make(AccountUserService::class);
        $this->user = $this->createAccountUser('Attendance User');
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_confirm_creates_active_commitment_and_lists_confirmed_occurrences(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);

        $response = $this->postJson("{$this->base_api_tenant}events/{$event->_id}/attendance/confirm", [
            'occurrence_id' => $occurrenceId,
        ]);
        $response->assertOk();
        $response->assertJsonPath('event_id', (string) $event->_id);
        $response->assertJsonPath('occurrence_id', $occurrenceId);
        $response->assertJsonPath('kind', 'free_confirmation');
        $response->assertJsonPath('status', 'active');

        $this->assertDatabaseCount('attendance_commitments', 1);

        $list = $this->getJson("{$this->base_api_tenant}events/attendance/confirmed");
        $list->assertOk();
        $list->assertJsonPath('data.confirmed_occurrence_ids.0', $occurrenceId);
    }

    public function test_unconfirm_cancels_commitment_and_removes_from_confirmed_list(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);

        $this->postJson("{$this->base_api_tenant}events/{$event->_id}/attendance/confirm", [
            'occurrence_id' => $occurrenceId,
        ])
            ->assertOk();

        $response = $this->postJson("{$this->base_api_tenant}events/{$event->_id}/attendance/unconfirm", [
            'occurrence_id' => $occurrenceId,
        ]);
        $response->assertOk();
        $response->assertJsonPath('event_id', (string) $event->_id);
        $response->assertJsonPath('occurrence_id', $occurrenceId);
        $response->assertJsonPath('status', 'canceled');

        $commitment = AttendanceCommitment::query()
            ->where('user_id', (string) $this->user->_id)
            ->where('event_id', (string) $event->_id)
            ->first();
        $this->assertNotNull($commitment);
        $this->assertSame('canceled', (string) $commitment->status);

        $list = $this->getJson("{$this->base_api_tenant}events/attendance/confirmed");
        $list->assertOk();
        $this->assertSame([], $list->json('data.confirmed_occurrence_ids'));
    }

    public function test_confirm_requires_occurrence_identity(): void
    {
        $event = $this->createEvent();

        $response = $this->postJson("{$this->base_api_tenant}events/{$event->_id}/attendance/confirm", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['occurrence_id']);
        $this->assertDatabaseCount('attendance_commitments', 0);
    }

    public function test_confirm_requires_authentication(): void
    {
        auth('sanctum')->forgetUser();
        auth()->forgetGuards();

        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);

        $response = $this->postJson("{$this->base_api_tenant}events/{$event->_id}/attendance/confirm", [
            'occurrence_id' => $occurrenceId,
        ]);
        $response->assertStatus(401);
        $this->assertDatabaseCount('attendance_commitments', 0);
    }

    public function test_confirm_supersedes_pending_invites_without_crediting_any_inviter(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);
        $firstInviter = $this->createAccountUser('First Inviter');
        $secondInviter = $this->createAccountUser('Second Inviter');

        $firstInvite = $this->createPendingInvite(
            inviter: $firstInviter,
            receiver: $this->user,
            event: $event,
            occurrenceId: $occurrenceId,
        );
        $secondInvite = $this->createPendingInvite(
            inviter: $secondInviter,
            receiver: $this->user,
            event: $event,
            occurrenceId: $occurrenceId,
        );

        $response = $this->postJson("{$this->base_api_tenant}events/{$event->_id}/attendance/confirm", [
            'occurrence_id' => $occurrenceId,
        ]);
        $response->assertOk();
        $response->assertJsonPath('status', 'active');

        $firstInvite = $firstInvite->fresh();
        $secondInvite = $secondInvite->fresh();

        $this->assertSame('superseded', (string) $firstInvite?->status);
        $this->assertSame('direct_confirmation', (string) $firstInvite?->supersession_reason);
        $this->assertFalse((bool) $firstInvite?->credited_acceptance);

        $this->assertSame('superseded', (string) $secondInvite?->status);
        $this->assertSame('direct_confirmation', (string) $secondInvite?->supersession_reason);
        $this->assertFalse((bool) $secondInvite?->credited_acceptance);

        $receiverAccountProfileId = $this->personalAccountProfileIdFor($this->user);
        $creditedCount = InviteEdge::query()
            ->where('receiver_account_profile_id', $receiverAccountProfileId)
            ->where('event_id', (string) $event->_id)
            ->where('occurrence_id', $occurrenceId)
            ->where('credited_acceptance', true)
            ->count();
        $this->assertSame(0, $creditedCount);
    }

    public function test_confirm_supersedes_only_pending_invites_for_same_occurrence(): void
    {
        $event = $this->createEventWithOccurrences();
        $occurrences = EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('occurrence_index')
            ->get();
        $firstOccurrenceId = (string) $occurrences->get(0)?->_id;
        $secondOccurrenceId = (string) $occurrences->get(1)?->_id;
        $this->assertNotSame('', $firstOccurrenceId);
        $this->assertNotSame('', $secondOccurrenceId);

        $inviter = $this->createAccountUser('Occurrence Inviter');
        $sameOccurrenceInvite = $this->createPendingInvite(
            inviter: $inviter,
            receiver: $this->user,
            event: $event,
            occurrenceId: $firstOccurrenceId,
        );
        $otherOccurrenceInvite = $this->createPendingInvite(
            inviter: $inviter,
            receiver: $this->user,
            event: $event,
            occurrenceId: $secondOccurrenceId,
        );

        $this->postJson("{$this->base_api_tenant}events/{$event->_id}/attendance/confirm", [
            'occurrence_id' => $firstOccurrenceId,
        ])->assertOk();

        $this->assertSame('superseded', (string) $sameOccurrenceInvite->fresh()?->status);
        $this->assertSame('pending', (string) $otherOccurrenceInvite->fresh()?->status);
    }

    public function test_confirm_returns_404_for_unknown_event(): void
    {
        $response = $this->postJson("{$this->base_api_tenant}events/missing-event/attendance/confirm", [
            'occurrence_id' => (string) new \MongoDB\BSON\ObjectId(),
        ]);
        $response->assertStatus(404);
    }

    public function test_confirm_rejects_occurrence_from_another_event(): void
    {
        $firstEvent = $this->createEvent(['title' => 'First event']);
        $secondEvent = $this->createEvent(['title' => 'Second event']);

        $secondOccurrence = EventOccurrence::query()
            ->where('event_id', (string) $secondEvent->_id)
            ->first();
        $this->assertNotNull($secondOccurrence);

        $response = $this->postJson("{$this->base_api_tenant}events/{$firstEvent->_id}/attendance/confirm", [
            'occurrence_id' => (string) $secondOccurrence?->_id,
        ]);

        $response->assertStatus(404);
    }

    private function createAccountUser(string $name): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Attendance Test Role '.Str::random(6),
            'permissions' => ['*'],
        ]);

        return $this->userService->create($this->account, [
            'name' => $name,
            'email' => uniqid('attendance-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function createPendingInvite(
        AccountUser $inviter,
        AccountUser $receiver,
        Event $event,
        string $occurrenceId
    ): InviteEdge
    {
        $receiverAccountProfileId = $this->personalAccountProfileIdFor($receiver);

        return InviteEdge::query()->create([
            'event_id' => (string) $event->_id,
            'occurrence_id' => $occurrenceId,
            'receiver_user_id' => (string) $receiver->_id,
            'receiver_account_profile_id' => $receiverAccountProfileId,
            'receiver_contact_hash' => null,
            'inviter_principal' => [
                'kind' => 'user',
                'principal_id' => (string) $inviter->_id,
            ],
            'account_profile_id' => null,
            'issued_by_user_id' => (string) $inviter->_id,
            'inviter_display_name' => (string) $inviter->name,
            'inviter_avatar_url' => null,
            'status' => 'pending',
            'credited_acceptance' => false,
            'source' => 'direct_invite',
            'message' => 'Bora sim',
            'event_name' => (string) $event->title,
            'event_slug' => (string) $event->slug,
            'event_date' => $event->date_time_start,
            'event_image_url' => 'https://example.org/invite.jpg',
            'location_label' => 'Venue Name',
            'host_name' => 'Host Name',
            'tags' => ['music'],
            'attendance_policy' => 'free_confirmation_only',
            'expires_at' => Carbon::now()->addDay(),
            'accepted_at' => null,
            'declined_at' => null,
        ]);
    }

    private function personalAccountProfileIdFor(AccountUser $user): string
    {
        /** @var AccountProfile|null $existing */
        $existing = AccountProfile::query()
            ->where('created_by', (string) $user->_id)
            ->where('created_by_type', 'tenant')
            ->where('profile_type', 'personal')
            ->first();

        if ($existing instanceof AccountProfile) {
            return (string) $existing->_id;
        }

        /** @var AccountProfile $profile */
        $profile = AccountProfile::query()->create([
            'account_id' => (string) $this->account->_id,
            'profile_type' => 'personal',
            'display_name' => (string) ($user->name ?: 'Personal'),
            'visibility' => 'public',
            'discoverable_by_contacts' => true,
            'is_active' => true,
            'is_verified' => false,
            'created_by' => (string) $user->_id,
            'created_by_type' => 'tenant',
            'updated_by' => (string) $user->_id,
            'updated_by_type' => 'tenant',
        ]);

        return (string) $profile->_id;
    }

    private function firstOccurrenceId(Event $event): string
    {
        $occurrence = EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('occurrence_index')
            ->firstOrFail();

        return (string) $occurrence->_id;
    }

    private function createEventWithOccurrences(): Event
    {
        $event = $this->createEvent();
        $now = Carbon::now();

        app(EventOccurrenceSyncService::class)->syncFromEvent($event, [
            [
                'date_time_start' => $now->copy()->addDay(),
                'date_time_end' => $now->copy()->addDay()->addHours(2),
            ],
            [
                'date_time_start' => $now->copy()->addDays(2),
                'date_time_end' => $now->copy()->addDays(2)->addHours(2),
            ],
        ]);

        return $event->fresh();
    }

    private function createEvent(array $overrides = []): Event
    {
        $now = Carbon::now();

        $event = Event::create(array_merge([
            'title' => 'Attendance Test Event',
            'content' => 'Event content',
            'location' => [
                'mode' => 'physical',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.0, -20.0],
                ],
            ],
            'place_ref' => [
                'type' => 'venue',
                'id' => 'venue-1',
                'metadata' => [
                    'display_name' => 'Venue Name',
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
            'venue' => [
                'id' => 'venue-1',
                'display_name' => 'Venue Name',
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
            'date_time_start' => $now->copy()->addDay(),
            'date_time_end' => $now->copy()->addDay()->addHours(2),
            'artists' => [],
            'tags' => ['music'],
            'categories' => ['culture'],
            'taxonomy_terms' => [],
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subMinute(),
            ],
            'is_active' => true,
        ], $overrides));

        $occurrences = [[
            'date_time_start' => Carbon::instance($event->date_time_start),
            'date_time_end' => $event->date_time_end ? Carbon::instance($event->date_time_end) : null,
        ]];

        app(EventOccurrenceSyncService::class)->syncFromEvent($event, $occurrences);

        return $event->fresh();
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
