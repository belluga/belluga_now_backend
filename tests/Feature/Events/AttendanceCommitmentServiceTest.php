<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Application\Accounts\AccountUserService;
use App\Application\Events\AttendanceCommitmentService;
use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Domain\Events\Events\OccurrenceAttendanceConfirmed;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\AttendanceCommitment;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Application\Transactions\EventTransactionRunner;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Invites\Application\Feed\InviteProjectionService;
use Belluga\Invites\Application\Mutations\InviteMutationService;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Helpers\TenantLabels;
use Tests\TestCaseTenant;
use Tests\Traits\RefreshLandlordAndTenantDatabases;
use Tests\Traits\SeedsTenantAccounts;

class AttendanceCommitmentServiceTest extends TestCaseTenant
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
        config(['queue.default' => 'sync']);

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
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_direct_confirmation_commits_attendance_and_same_target_invite_supersession_atomically(): void
    {
        $event = $this->createEventWithOccurrences();
        [$occurrenceId, $otherOccurrenceId] = $this->occurrenceIdsForEvent($event);
        $firstInviter = $this->createAccountUser('First Inviter');
        $secondInviter = $this->createAccountUser('Second Inviter');
        $acceptedInviter = $this->createAccountUser('Accepted Inviter');

        $pendingInvite = $this->createInvite($firstInviter, $this->user, $event, $occurrenceId, 'pending');
        $viewedInvite = $this->createInvite($secondInviter, $this->user, $event, $occurrenceId, 'viewed');
        $otherOccurrenceInvite = $this->createInvite($firstInviter, $this->user, $event, $otherOccurrenceId, 'pending');
        $acceptedInvite = $this->createInvite(
            inviter: $acceptedInviter,
            receiver: $this->user,
            event: $event,
            occurrenceId: $occurrenceId,
            status: 'accepted',
            creditedAcceptance: true,
        );

        EventFacade::fake([OccurrenceAttendanceConfirmed::class]);

        $commitment = $this->attendanceService()->confirm(
            userId: (string) $this->user->_id,
            eventId: (string) $event->_id,
            occurrenceId: $occurrenceId,
        );

        $this->assertSame('active', (string) $commitment->status);
        $this->assertDatabaseCount('attendance_commitments', 1);

        $this->assertSame('superseded', (string) $pendingInvite->fresh()?->status);
        $this->assertSame('direct_confirmation', (string) $pendingInvite->fresh()?->supersession_reason);
        $this->assertFalse((bool) $pendingInvite->fresh()?->credited_acceptance);

        $this->assertSame('superseded', (string) $viewedInvite->fresh()?->status);
        $this->assertSame('direct_confirmation', (string) $viewedInvite->fresh()?->supersession_reason);
        $this->assertFalse((bool) $viewedInvite->fresh()?->credited_acceptance);

        $this->assertSame('pending', (string) $otherOccurrenceInvite->fresh()?->status);
        $this->assertSame('accepted', (string) $acceptedInvite->fresh()?->status);
        $this->assertTrue((bool) $acceptedInvite->fresh()?->credited_acceptance);

        $userId = (string) $this->user->_id;
        EventFacade::assertDispatched(OccurrenceAttendanceConfirmed::class, static function (OccurrenceAttendanceConfirmed $eventPayload) use ($event, $occurrenceId, $userId): bool {
            return $eventPayload->userId === $userId
                && $eventPayload->eventId === (string) $event->_id
                && $eventPayload->occurrenceId === $occurrenceId;
        });
    }

    public function test_direct_confirmation_rolls_back_attendance_when_supersession_fails(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);
        $inviter = $this->createAccountUser('Rollback Inviter');
        $pendingInvite = $this->createInvite($inviter, $this->user, $event, $occurrenceId, 'pending');

        $inviteMutations = Mockery::mock(InviteMutationService::class);
        $inviteMutations->shouldReceive('prepareReceiverForDirectConfirmation')
            ->once()
            ->with((string) $this->user->_id)
            ->andReturn($this->personalAccountProfileIdFor($this->user));
        $inviteMutations->shouldReceive('supersedePendingInvitesForDirectConfirmation')
            ->once()
            ->with((string) $this->user->_id, (string) $event->_id, $occurrenceId)
            ->andThrow(new RuntimeException('forced supersession failure'));
        $this->app->instance(InviteMutationService::class, $inviteMutations);

        EventFacade::fake([OccurrenceAttendanceConfirmed::class]);

        try {
            $this->attendanceService()->confirm(
                userId: (string) $this->user->_id,
                eventId: (string) $event->_id,
                occurrenceId: $occurrenceId,
            );
            $this->fail('Direct confirmation must fail when invite supersession fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced supersession failure', $exception->getMessage());
        }

        $this->assertSame(0, $this->activeCommitmentCount($event, $occurrenceId));
        $this->assertSame('pending', (string) $pendingInvite->fresh()?->status);
        EventFacade::assertNotDispatched(OccurrenceAttendanceConfirmed::class);
    }

    public function test_direct_confirmation_rolls_back_attendance_and_real_invite_supersession_when_projection_fails(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);
        $inviter = $this->createAccountUser('Projection Failure Inviter');
        $pendingInvite = $this->createInvite($inviter, $this->user, $event, $occurrenceId, 'pending');

        $projection = Mockery::mock(InviteProjectionService::class);
        $projection->shouldReceive('rebuildReceiverTargetProjection')
            ->once()
            ->with(
                (string) $this->user->_id,
                Mockery::on(static fn (array $targetRef): bool => ($targetRef['event_id'] ?? null) === (string) $event->_id
                    && ($targetRef['occurrence_id'] ?? null) === $occurrenceId)
            )
            ->andThrow(new RuntimeException('forced projection failure'));
        $this->app->instance(InviteProjectionService::class, $projection);
        $this->app->forgetInstance(InviteMutationService::class);

        EventFacade::fake([OccurrenceAttendanceConfirmed::class]);

        try {
            $this->attendanceService()->confirm(
                userId: (string) $this->user->_id,
                eventId: (string) $event->_id,
                occurrenceId: $occurrenceId,
            );
            $this->fail('Direct confirmation must roll back when a real invite supersession side effect fails in-transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced projection failure', $exception->getMessage());
        }

        $this->assertSame(0, $this->activeCommitmentCount($event, $occurrenceId));
        $this->assertSame('pending', (string) $pendingInvite->fresh()?->status);
        $this->assertNull($pendingInvite->fresh()?->supersession_reason);
        EventFacade::assertNotDispatched(OccurrenceAttendanceConfirmed::class);
    }

    public function test_direct_confirmation_fails_closed_when_tenant_transactions_are_unavailable(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);
        $inviter = $this->createAccountUser('Transaction Inviter');
        $pendingInvite = $this->createInvite($inviter, $this->user, $event, $occurrenceId, 'pending');

        $transactions = Mockery::mock(EventTransactionRunner::class);
        $transactions->shouldReceive('run')
            ->once()
            ->andThrow(new RuntimeException('Tenant MongoDB transaction support is required for events writes.'));
        $this->app->instance(EventTransactionRunner::class, $transactions);

        EventFacade::fake([OccurrenceAttendanceConfirmed::class]);

        try {
            $this->attendanceService()->confirm(
                userId: (string) $this->user->_id,
                eventId: (string) $event->_id,
                occurrenceId: $occurrenceId,
            );
            $this->fail('Direct confirmation must fail closed without tenant transaction support.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('transaction support is required', $exception->getMessage());
        }

        $this->assertSame(0, $this->activeCommitmentCount($event, $occurrenceId));
        $this->assertSame('pending', (string) $pendingInvite->fresh()?->status);
        EventFacade::assertNotDispatched(OccurrenceAttendanceConfirmed::class);
    }

    public function test_direct_confirmation_does_not_overwrite_credited_invite_attribution(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);
        $creditedInviter = $this->createAccountUser('Credited Inviter');
        $otherInviter = $this->createAccountUser('Other Inviter');

        $acceptedInvite = $this->createInvite(
            inviter: $creditedInviter,
            receiver: $this->user,
            event: $event,
            occurrenceId: $occurrenceId,
            status: 'accepted',
            creditedAcceptance: true,
        );
        $otherCreditedSupersededInvite = $this->createInvite(
            inviter: $otherInviter,
            receiver: $this->user,
            event: $event,
            occurrenceId: $occurrenceId,
            status: 'superseded',
            supersessionReason: 'other_invite_credited',
        );

        $this->attendanceService()->confirm(
            userId: (string) $this->user->_id,
            eventId: (string) $event->_id,
            occurrenceId: $occurrenceId,
        );

        $acceptedInvite = $acceptedInvite->fresh();
        $otherCreditedSupersededInvite = $otherCreditedSupersededInvite->fresh();

        $this->assertSame('accepted', (string) $acceptedInvite?->status);
        $this->assertTrue((bool) $acceptedInvite?->credited_acceptance);
        $this->assertNull($acceptedInvite?->supersession_reason);

        $this->assertSame('superseded', (string) $otherCreditedSupersededInvite?->status);
        $this->assertFalse((bool) $otherCreditedSupersededInvite?->credited_acceptance);
        $this->assertSame('other_invite_credited', (string) $otherCreditedSupersededInvite?->supersession_reason);
    }

    public function test_direct_confirmation_and_invite_acceptance_race_has_deterministic_final_state(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);
        $winningInviter = $this->createAccountUser('Winning Inviter');
        $competingInviter = $this->createAccountUser('Competing Inviter');

        $acceptedInvite = $this->createInvite($winningInviter, $this->user, $event, $occurrenceId, 'pending');
        $competingInvite = $this->createInvite($competingInviter, $this->user, $event, $occurrenceId, 'pending');

        $acceptance = $this->app->make(InviteMutationService::class)->acceptForUserId(
            userId: (string) $this->user->_id,
            inviteId: (string) $acceptedInvite->_id,
        );
        $this->assertSame('accepted', $acceptance['status']);

        $this->attendanceService()->confirm(
            userId: (string) $this->user->_id,
            eventId: (string) $event->_id,
            occurrenceId: $occurrenceId,
        );

        $acceptedInvite = $acceptedInvite->fresh();
        $competingInvite = $competingInvite->fresh();

        $this->assertSame('accepted', (string) $acceptedInvite?->status);
        $this->assertTrue((bool) $acceptedInvite?->credited_acceptance);
        $this->assertNull($acceptedInvite?->supersession_reason);

        $this->assertSame('superseded', (string) $competingInvite?->status);
        $this->assertSame('other_invite_credited', (string) $competingInvite?->supersession_reason);

        $leftoverPending = InviteEdge::query()
            ->where('event_id', (string) $event->_id)
            ->where('occurrence_id', $occurrenceId)
            ->whereIn('status', ['pending', 'viewed'])
            ->count();
        $this->assertSame(0, $leftoverPending);
    }

    public function test_invite_accept_after_direct_confirmation_cannot_late_bind_credited_attribution(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);
        $inviter = $this->createAccountUser('Late Accept Inviter');
        $pendingInvite = $this->createInvite($inviter, $this->user, $event, $occurrenceId, 'pending');

        $this->attendanceService()->confirm(
            userId: (string) $this->user->_id,
            eventId: (string) $event->_id,
            occurrenceId: $occurrenceId,
        );

        $afterConfirmation = $pendingInvite->fresh();
        $this->assertSame('superseded', (string) $afterConfirmation?->status);
        $this->assertSame('direct_confirmation', (string) $afterConfirmation?->supersession_reason);

        $acceptance = $this->app->make(InviteMutationService::class)->acceptForUserId(
            userId: (string) $this->user->_id,
            inviteId: (string) $pendingInvite->_id,
        );
        $this->assertSame('already_accepted', $acceptance['status']);
        $this->assertFalse((bool) $acceptance['credited_acceptance']);

        $afterLateAccept = $pendingInvite->fresh();
        $this->assertSame('superseded', (string) $afterLateAccept?->status);
        $this->assertSame('direct_confirmation', (string) $afterLateAccept?->supersession_reason);
        $this->assertFalse((bool) $afterLateAccept?->credited_acceptance);
    }

    public function test_repeated_direct_confirmation_is_idempotent(): void
    {
        $event = $this->createEvent();
        $occurrenceId = $this->firstOccurrenceId($event);
        $firstInviter = $this->createAccountUser('First Repeat Inviter');
        $secondInviter = $this->createAccountUser('Second Repeat Inviter');
        $firstInvite = $this->createInvite($firstInviter, $this->user, $event, $occurrenceId, 'pending');
        $secondInvite = $this->createInvite($secondInviter, $this->user, $event, $occurrenceId, 'viewed');

        $this->attendanceService()->confirm((string) $this->user->_id, (string) $event->_id, $occurrenceId);
        $this->attendanceService()->confirm((string) $this->user->_id, (string) $event->_id, $occurrenceId);

        $this->assertSame(1, $this->activeCommitmentCount($event, $occurrenceId));
        $this->assertSame('superseded', (string) $firstInvite->fresh()?->status);
        $this->assertSame('direct_confirmation', (string) $firstInvite->fresh()?->supersession_reason);
        $this->assertSame('superseded', (string) $secondInvite->fresh()?->status);
        $this->assertSame('direct_confirmation', (string) $secondInvite->fresh()?->supersession_reason);
    }

    public function test_direct_confirmation_supersession_query_is_bounded_to_target_and_pending_viewed_statuses(): void
    {
        $event = $this->createEventWithOccurrences();
        [$occurrenceId, $otherOccurrenceId] = $this->occurrenceIdsForEvent($event);
        $otherEvent = $this->createEvent(['title' => 'Other Event']);
        $otherEventOccurrenceId = $this->firstOccurrenceId($otherEvent);
        $pendingInviter = $this->createAccountUser('Predicate Pending Inviter');
        $viewedInviter = $this->createAccountUser('Predicate Viewed Inviter');
        $declinedInviter = $this->createAccountUser('Predicate Declined Inviter');
        $expiredInviter = $this->createAccountUser('Predicate Expired Inviter');
        $suppressedInviter = $this->createAccountUser('Predicate Suppressed Inviter');
        $supersededInviter = $this->createAccountUser('Predicate Superseded Inviter');
        $unrelatedInviter = $this->createAccountUser('Predicate Unrelated Inviter');
        $otherReceiver = $this->createAccountUser('Predicate Other Receiver');

        $pendingInvite = $this->createInvite($pendingInviter, $this->user, $event, $occurrenceId, 'pending');
        $viewedInvite = $this->createInvite($viewedInviter, $this->user, $event, $occurrenceId, 'viewed');
        $declinedInvite = $this->createInvite($declinedInviter, $this->user, $event, $occurrenceId, 'declined');
        $expiredInvite = $this->createInvite($expiredInviter, $this->user, $event, $occurrenceId, 'expired');
        $suppressedInvite = $this->createInvite($suppressedInviter, $this->user, $event, $occurrenceId, 'suppressed');
        $alreadySupersededInvite = $this->createInvite(
            inviter: $supersededInviter,
            receiver: $this->user,
            event: $event,
            occurrenceId: $occurrenceId,
            status: 'superseded',
            supersessionReason: 'other_invite_credited',
        );
        $otherReceiverInvite = $this->createInvite($unrelatedInviter, $otherReceiver, $event, $occurrenceId, 'pending');
        $otherOccurrenceInvite = $this->createInvite($unrelatedInviter, $this->user, $event, $otherOccurrenceId, 'pending');
        $otherEventInvite = $this->createInvite($unrelatedInviter, $this->user, $otherEvent, $otherEventOccurrenceId, 'pending');

        $this->attendanceService()->confirm(
            userId: (string) $this->user->_id,
            eventId: (string) $event->_id,
            occurrenceId: $occurrenceId,
        );

        $this->assertSame('superseded', (string) $pendingInvite->fresh()?->status);
        $this->assertSame('direct_confirmation', (string) $pendingInvite->fresh()?->supersession_reason);
        $this->assertSame('superseded', (string) $viewedInvite->fresh()?->status);
        $this->assertSame('direct_confirmation', (string) $viewedInvite->fresh()?->supersession_reason);

        $this->assertSame('declined', (string) $declinedInvite->fresh()?->status);
        $this->assertSame('expired', (string) $expiredInvite->fresh()?->status);
        $this->assertSame('suppressed', (string) $suppressedInvite->fresh()?->status);
        $this->assertSame('superseded', (string) $alreadySupersededInvite->fresh()?->status);
        $this->assertSame('other_invite_credited', (string) $alreadySupersededInvite->fresh()?->supersession_reason);
        $this->assertSame('pending', (string) $otherReceiverInvite->fresh()?->status);
        $this->assertSame('pending', (string) $otherOccurrenceInvite->fresh()?->status);
        $this->assertSame('pending', (string) $otherEventInvite->fresh()?->status);
    }

    private function attendanceService(): AttendanceCommitmentService
    {
        return $this->app->make(AttendanceCommitmentService::class);
    }

    private function activeCommitmentCount(Event $event, string $occurrenceId): int
    {
        return AttendanceCommitment::query()
            ->where('user_id', (string) $this->user->_id)
            ->where('event_id', (string) $event->_id)
            ->where('occurrence_id', $occurrenceId)
            ->where('status', 'active')
            ->count();
    }

    private function createAccountUser(string $name): AccountUser
    {
        $role = $this->account->roleTemplates()->create([
            'name' => 'Attendance Service Test Role '.Str::random(6),
            'permissions' => ['*'],
        ]);

        return $this->userService->create($this->account, [
            'name' => $name,
            'email' => uniqid('attendance-service-user', true).'@example.org',
            'password' => 'Secret!234',
        ], (string) $role->_id);
    }

    private function createInvite(
        AccountUser $inviter,
        AccountUser $receiver,
        Event $event,
        string $occurrenceId,
        string $status,
        bool $creditedAcceptance = false,
        ?string $supersessionReason = null,
    ): InviteEdge {
        $receiverAccountProfileId = $this->personalAccountProfileIdFor($receiver);
        $now = Carbon::now();

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
            'status' => $status,
            'credited_acceptance' => $creditedAcceptance,
            'supersession_reason' => $supersessionReason,
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
            'expires_at' => $now->copy()->addDay(),
            'accepted_at' => $status === 'accepted' ? $now : null,
            'declined_at' => $status === 'declined' ? $now : null,
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

        $personalAccount = Account::query()->create([
            'name' => 'Personal '.$user->_id,
            'ownership_state' => 'unmanaged',
            'document' => [
                'type' => 'cpf',
                'number' => 'PERSONAL-'.(string) $user->_id,
            ],
            'created_by' => (string) $user->_id,
            'created_by_type' => 'tenant',
            'updated_by' => (string) $user->_id,
            'updated_by_type' => 'tenant',
        ]);

        $profile = AccountProfile::query()->create([
            'account_id' => (string) $personalAccount->_id,
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
        $occurrenceIds = $this->occurrenceIdsForEvent($event);
        $this->assertNotSame([], $occurrenceIds);

        return $occurrenceIds[0];
    }

    /**
     * @return array<int, string>
     */
    private function occurrenceIdsForEvent(Event $event): array
    {
        return EventOccurrence::query()
            ->where('event_id', (string) $event->_id)
            ->orderBy('starts_at')
            ->orderBy('_id')
            ->get()
            ->map(static fn (EventOccurrence $occurrence): string => (string) $occurrence->_id)
            ->values()
            ->all();
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

        app(EventOccurrenceSyncService::class)->syncFromEvent($event, [[
            'date_time_start' => Carbon::instance($event->date_time_start),
            'date_time_end' => $event->date_time_end ? Carbon::instance($event->date_time_end) : null,
        ]]);

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
