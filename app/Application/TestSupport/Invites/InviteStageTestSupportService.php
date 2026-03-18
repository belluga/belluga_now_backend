<?php

declare(strict_types=1);

namespace App\Application\TestSupport\Invites;

use App\Application\Accounts\AccountManagementService;
use App\Application\Accounts\AccountUserService;
use App\Application\Identity\TenantPasswordRegistrationService;
use App\Models\Landlord\PersonalAccessToken;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\Account;
use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use App\Models\Tenants\AttendanceCommitment;
use App\Models\Tenants\InviteStageTestSupportRun;
use Belluga\Events\Application\Events\EventOccurrenceSyncService;
use Belluga\Events\Models\Tenants\Event;
use Belluga\Events\Models\Tenants\EventOccurrence;
use Belluga\Invites\Application\Mutations\InviteMutationService;
use Belluga\Invites\Application\Mutations\InviteShareService;
use Belluga\Invites\Models\Tenants\InviteCommandIdempotency;
use Belluga\Invites\Models\Tenants\InviteEdge;
use Belluga\Invites\Models\Tenants\InviteFeedProjection;
use Belluga\Invites\Models\Tenants\InviteShareCode;
use Belluga\Invites\Models\Tenants\PrincipalSocialMetric;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteStageTestSupportService
{
    private const SCENARIO_ACCEPT_PENDING = 'accept_pending';

    private const SCENARIO_DECLINE_PENDING = 'decline_pending';

    private const SCENARIO_DIRECT_CONFIRMATION_SUPERSEDED = 'direct_confirmation_superseded';

    private const SCENARIO_EXPIRED_SHARE = 'expired_share';

    public function __construct(
        private readonly AccountManagementService $accountManagementService,
        private readonly AccountUserService $accountUserService,
        private readonly TenantPasswordRegistrationService $registrationService,
        private readonly InviteShareService $inviteShareService,
        private readonly InviteMutationService $inviteMutationService,
        private readonly EventOccurrenceSyncService $eventOccurrenceSyncService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(Tenant $tenant, string $runId, string $scenario): array
    {
        $normalizedRunId = $this->normalizeRunId($runId);
        $normalizedScenario = $this->normalizeScenario($scenario);

        $tenant->makeCurrent();
        $this->cleanup($tenant, $normalizedRunId);

        $fixtureAccount = $this->accountManagementService->create([
            'name' => 'Stage Invite Fixture '.Str::upper(Str::substr($normalizedRunId, 0, 12)),
            'ownership_state' => 'unmanaged',
            'document' => 'STAGE-'.Str::upper(Str::random(14)),
            'created_by' => 'stage-test-support',
            'created_by_type' => 'system',
            'updated_by' => 'stage-test-support',
            'updated_by_type' => 'system',
        ]);

        /** @var Account $account */
        $account = $fixtureAccount['account'];
        $role = $fixtureAccount['role'];

        $inviterPassword = $this->fixturePassword('inviter');
        $inviter = $this->accountUserService->create($account, [
            'name' => 'Stage Invite Inviter '.Str::upper(Str::substr($normalizedRunId, 0, 6)),
            'email' => $this->fixtureEmail($normalizedRunId, 'inviter'),
            'password' => $inviterPassword,
        ], (string) $role->getAttribute('_id'));

        $secondInviter = null;
        $secondInviterPassword = null;
        if (in_array($normalizedScenario, [self::SCENARIO_ACCEPT_PENDING, self::SCENARIO_DECLINE_PENDING], true)) {
            $secondInviterPassword = $this->fixturePassword('second-inviter');
            $secondInviter = $this->accountUserService->create($account, [
                'name' => 'Stage Invite Rival '.Str::upper(Str::substr($normalizedRunId, 0, 6)),
                'email' => $this->fixtureEmail($normalizedRunId, 'second-inviter'),
                'password' => $secondInviterPassword,
            ], (string) $role->getAttribute('_id'));
        }

        $inviteePassword = $this->fixturePassword('invitee');
        $inviteeRegistration = $this->registrationService->register($tenant, [
            'name' => 'Stage Invite Invitee '.Str::upper(Str::substr($normalizedRunId, 0, 6)),
            'email' => $this->fixtureEmail($normalizedRunId, 'invitee'),
            'password' => $inviteePassword,
            'anonymous_user_ids' => [],
        ]);
        $invitee = $inviteeRegistration->user->fresh();

        $event = $this->createEvent($normalizedRunId);
        $sharePayload = $this->inviteShareService->create($inviter, [
            'target_ref' => [
                'event_id' => (string) $event->getAttribute('_id'),
            ],
        ]);
        $shareCode = (string) ($sharePayload['code'] ?? '');
        if ($shareCode === '') {
            throw ValidationException::withMessages([
                'scenario' => ['Failed to provision invite share code.'],
            ]);
        }

        if ($normalizedScenario === self::SCENARIO_EXPIRED_SHARE) {
            InviteShareCode::query()->where('code', $shareCode)->update([
                'expires_at' => Carbon::now()->subMinute(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $directInviteIds = [];
        if ($secondInviter instanceof AccountUser) {
            $sendResult = $this->inviteMutationService->send($secondInviter, [
                'target_ref' => [
                    'event_id' => (string) $event->getAttribute('_id'),
                ],
                'recipients' => [
                    ['receiver_user_id' => (string) $invitee->getAttribute('_id')],
                ],
            ]);

            $directInviteIds = array_values(array_filter(array_map(
                static fn (mixed $invite): string => is_array($invite) ? (string) ($invite['invite_id'] ?? '') : '',
                (array) ($sendResult['created'] ?? [])
            )));
        }

        $inviteeAccountIds = $invitee->getAccessToIds();
        $inviteeProfileIds = AccountProfile::query()
            ->whereIn('account_id', $inviteeAccountIds)
            ->pluck('_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        InviteStageTestSupportRun::query()->create([
            'run_id' => $normalizedRunId,
            'scenario' => $normalizedScenario,
            'tenant_slug' => (string) $tenant->slug,
            'event_id' => (string) $event->getAttribute('_id'),
            'occurrence_id' => $this->primaryOccurrenceId((string) $event->getAttribute('_id')),
            'share_code' => $shareCode,
            'invite_url' => $this->inviteUrl($tenant, $shareCode),
            'refs' => [
                'fixture_account_id' => (string) $account->getAttribute('_id'),
                'fixture_role_id' => (string) $role->getAttribute('_id'),
                'inviter_user_id' => (string) $inviter->getAttribute('_id'),
                'second_inviter_user_id' => $secondInviter ? (string) $secondInviter->getAttribute('_id') : null,
                'invitee_user_id' => (string) $invitee->getAttribute('_id'),
                'invitee_account_ids' => array_values($inviteeAccountIds),
                'invitee_profile_ids' => $inviteeProfileIds,
                'direct_invite_ids' => $directInviteIds,
            ],
            'credentials' => [
                'inviter' => [
                    'email' => $inviter->emails[0] ?? null,
                    'password' => $inviterPassword,
                ],
                'second_inviter' => $secondInviter ? [
                    'email' => $secondInviter->emails[0] ?? null,
                    'password' => $secondInviterPassword,
                ] : null,
                'invitee' => [
                    'email' => $invitee->emails[0] ?? null,
                    'password' => $inviteePassword,
                ],
                'signup_candidate' => [
                    'name' => 'Stage Signup Candidate '.Str::upper(Str::substr($normalizedRunId, 0, 6)),
                    'email' => $this->fixtureEmail($normalizedRunId, 'signup'),
                    'password' => $this->fixturePassword('signup'),
                ],
            ],
        ]);

        return $this->statefulBootstrapResponse($tenant, $normalizedRunId);
    }

    /**
     * @return array<string, mixed>
     */
    public function state(Tenant $tenant, string $runId): array
    {
        $tenant->makeCurrent();
        $run = $this->findRunOrFail($runId);
        $refs = is_array($run->refs ?? null) ? $run->refs : [];
        $inviteeUserId = (string) ($refs['invitee_user_id'] ?? '');

        $invites = InviteEdge::query()
            ->where('receiver_user_id', $inviteeUserId)
            ->where('event_id', (string) $run->event_id)
            ->orderBy('created_at')
            ->get()
            ->map(static function (InviteEdge $invite): array {
                return [
                    'invite_id' => (string) $invite->getAttribute('_id'),
                    'receiver_user_id' => (string) $invite->receiver_user_id,
                    'status' => (string) $invite->status,
                    'credited_acceptance' => (bool) $invite->credited_acceptance,
                    'supersession_reason' => $invite->supersession_reason ? (string) $invite->supersession_reason : null,
                ];
            })
            ->values()
            ->all();

        $attendance = AttendanceCommitment::query()
            ->where('user_id', $inviteeUserId)
            ->where('event_id', (string) $run->event_id)
            ->first();

        return [
            'run_id' => (string) $run->run_id,
            'scenario' => (string) $run->scenario,
            'event_id' => (string) $run->event_id,
            'share_code' => (string) $run->share_code,
            'invites' => $invites,
            'attendance' => $attendance ? [
                'status' => (string) $attendance->status,
                'kind' => (string) $attendance->kind,
            ] : null,
        ];
    }

    /**
     * @return array{run_id:string,deleted:bool}
     */
    public function cleanup(Tenant $tenant, string $runId): array
    {
        $tenant->makeCurrent();
        $normalizedRunId = $this->normalizeRunId($runId);

        /** @var InviteStageTestSupportRun|null $run */
        $run = InviteStageTestSupportRun::query()->where('run_id', $normalizedRunId)->first();
        if (! $run instanceof InviteStageTestSupportRun) {
            return [
                'run_id' => $normalizedRunId,
                'deleted' => true,
            ];
        }

        $refs = is_array($run->refs ?? null) ? $run->refs : [];
        $userIds = array_values(array_filter([
            (string) ($refs['inviter_user_id'] ?? ''),
            (string) ($refs['second_inviter_user_id'] ?? ''),
            (string) ($refs['invitee_user_id'] ?? ''),
        ]));
        $accountIds = array_values(array_filter(array_merge(
            [(string) ($refs['fixture_account_id'] ?? '')],
            array_map('strval', (array) ($refs['invitee_account_ids'] ?? [])),
        )));
        $profileIds = array_values(array_filter(array_map('strval', (array) ($refs['invitee_profile_ids'] ?? []))));

        InviteEdge::query()
            ->where('event_id', (string) $run->event_id)
            ->where('receiver_user_id', (string) ($refs['invitee_user_id'] ?? ''))
            ->delete();

        InviteFeedProjection::query()
            ->where('event_id', (string) $run->event_id)
            ->where('receiver_user_id', (string) ($refs['invitee_user_id'] ?? ''))
            ->delete();

        InviteShareCode::query()
            ->where('code', (string) $run->share_code)
            ->delete();

        AttendanceCommitment::query()
            ->where('event_id', (string) $run->event_id)
            ->where('user_id', (string) ($refs['invitee_user_id'] ?? ''))
            ->delete();

        if ($userIds !== []) {
            InviteCommandIdempotency::query()
                ->whereIn('actor_user_id', $userIds)
                ->delete();

            PersonalAccessToken::query()
                ->where('tokenable_type', AccountUser::class)
                ->whereIn('tokenable_id', $userIds)
                ->delete();
        }

        if ($userIds !== []) {
            PrincipalSocialMetric::query()
                ->whereIn('principal_id', $userIds)
                ->delete();
        }

        if ($profileIds !== []) {
            AccountProfile::withTrashed()
                ->whereIn('_id', $profileIds)
                ->get()
                ->each(static function (AccountProfile $profile): void {
                    $profile->forceDelete();
                });
        }

        if ($userIds !== []) {
            AccountUser::withTrashed()
                ->whereIn('_id', $userIds)
                ->get()
                ->each(static function (AccountUser $user): void {
                    if ($user->trashed()) {
                        $user->forceDelete();

                        return;
                    }

                    $user->forceDelete();
                });
        }

        if ($accountIds !== []) {
            Account::withTrashed()
                ->whereIn('_id', $accountIds)
                ->get()
                ->each(static function (Account $account): void {
                    $account->roleTemplates()->withTrashed()->get()->each(static function (mixed $role): void {
                        if (method_exists($role, 'forceDelete')) {
                            $role->forceDelete();
                        }
                    });

                    if ($account->trashed()) {
                        $account->forceDelete();

                        return;
                    }

                    $account->forceDelete();
                });
        }

        EventOccurrence::query()->where('event_id', (string) $run->event_id)->delete();
        Event::query()->where('_id', (string) $run->event_id)->delete();

        $run->delete();

        return [
            'run_id' => $normalizedRunId,
            'deleted' => true,
        ];
    }

    private function createEvent(string $runId): Event
    {
        $now = Carbon::now();

        $event = Event::query()->create([
            'title' => 'Stage Invite Event '.Str::upper(Str::substr($runId, 0, 8)),
            'slug' => 'stage-invite-event-'.Str::lower(Str::substr(Str::slug($runId), 0, 12)).'-'.Str::lower(Str::random(4)),
            'content' => 'Stage invite compatibility fixture.',
            'location' => [
                'mode' => 'physical',
                'label' => 'Stage Invite Venue',
                'geo' => [
                    'type' => 'Point',
                    'coordinates' => [-40.4984, -20.6714],
                ],
            ],
            'place_ref' => [
                'type' => 'venue',
                'id' => 'stage-invite-venue',
                'metadata' => ['display_name' => 'Stage Invite Venue'],
            ],
            'type' => [
                'id' => 'show',
                'name' => 'Show',
                'slug' => 'show',
            ],
            'venue' => [
                'id' => 'stage-invite-venue',
                'display_name' => 'Stage Invite Venue',
                'hero_image_url' => 'https://example.org/stage-invite-hero.jpg',
            ],
            'thumb' => [
                'url' => 'https://example.org/stage-invite-thumb.jpg',
            ],
            'date_time_start' => $now->copy()->addDay(),
            'date_time_end' => $now->copy()->addDay()->addHours(4),
            'tags' => ['music', 'stage-test'],
            'attendance_policy' => 'free_confirmation_only',
            'allow_occurrence_policy_override' => false,
            'publication' => [
                'status' => 'published',
                'publish_at' => $now->copy()->subMinute(),
            ],
            'is_active' => true,
        ]);

        $this->eventOccurrenceSyncService->syncFromEvent($event, [[
            'date_time_start' => Carbon::instance($event->date_time_start),
            'date_time_end' => $event->date_time_end ? Carbon::instance($event->date_time_end) : null,
        ]]);

        return $event->fresh();
    }

    private function primaryOccurrenceId(string $eventId): ?string
    {
        /** @var EventOccurrence|null $occurrence */
        $occurrence = EventOccurrence::query()
            ->where('event_id', $eventId)
            ->orderBy('date_time_start')
            ->first();

        return $occurrence ? (string) $occurrence->getAttribute('_id') : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function statefulBootstrapResponse(Tenant $tenant, string $runId): array
    {
        /** @var InviteStageTestSupportRun $run */
        $run = $this->findRunOrFail($runId);
        $credentials = is_array($run->credentials ?? null) ? $run->credentials : [];

        return [
            'run_id' => (string) $run->run_id,
            'tenant' => [
                'slug' => (string) $tenant->slug,
                'tenant_url' => rtrim($tenant->getMainDomain(), '/'),
                'landlord_url' => rtrim((string) config('app.url'), '/'),
            ],
            'mobile' => [
                'app_domain_identifier' => $tenant->appDomainIdentifierForPlatform(Tenant::APP_PLATFORM_ANDROID) ?? ($tenant->resolvedAppDomains()[0] ?? null),
            ],
            'inviter' => [
                'email' => (string) data_get($credentials, 'inviter.email', ''),
                'password' => (string) data_get($credentials, 'inviter.password', ''),
            ],
            'invitee' => [
                'email' => (string) data_get($credentials, 'invitee.email', ''),
                'password' => (string) data_get($credentials, 'invitee.password', ''),
            ],
            'signup_candidate' => [
                'name' => (string) data_get($credentials, 'signup_candidate.name', ''),
                'email' => (string) data_get($credentials, 'signup_candidate.email', ''),
                'password' => (string) data_get($credentials, 'signup_candidate.password', ''),
            ],
            'event_id' => (string) $run->event_id,
            'share_code' => (string) $run->share_code,
            'invite_url' => (string) $run->invite_url,
        ];
    }

    private function findRunOrFail(string $runId): InviteStageTestSupportRun
    {
        /** @var InviteStageTestSupportRun|null $run */
        $run = InviteStageTestSupportRun::query()
            ->where('run_id', $this->normalizeRunId($runId))
            ->first();

        if (! $run instanceof InviteStageTestSupportRun) {
            throw (new ModelNotFoundException)->setModel(InviteStageTestSupportRun::class, [$runId]);
        }

        return $run;
    }

    private function normalizeRunId(string $runId): string
    {
        $normalized = trim($runId);
        if ($normalized === '' || ! preg_match('/^[a-zA-Z0-9._-]{3,80}$/', $normalized)) {
            throw ValidationException::withMessages([
                'run_id' => ['run_id must use 3-80 chars from [a-zA-Z0-9._-].'],
            ]);
        }

        return $normalized;
    }

    private function normalizeScenario(string $scenario): string
    {
        $normalized = trim($scenario);
        if (! in_array($normalized, [
            self::SCENARIO_ACCEPT_PENDING,
            self::SCENARIO_DECLINE_PENDING,
            self::SCENARIO_DIRECT_CONFIRMATION_SUPERSEDED,
            self::SCENARIO_EXPIRED_SHARE,
        ], true)) {
            throw ValidationException::withMessages([
                'scenario' => ['Unsupported invite stage test support scenario.'],
            ]);
        }

        return $normalized;
    }

    private function fixtureEmail(string $runId, string $label): string
    {
        return sprintf(
            'stage-invite-%s-%s@example.org',
            Str::slug($runId),
            Str::slug($label),
        );
    }

    private function fixturePassword(string $label): string
    {
        return 'Stage!'.Str::upper(Str::substr(Str::slug($label, ''), 0, 6)).'234';
    }

    private function inviteUrl(Tenant $tenant, string $shareCode): string
    {
        return sprintf('%s/invite?code=%s', rtrim($tenant->getMainDomain(), '/'), $shareCode);
    }
}
