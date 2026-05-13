<?php

declare(strict_types=1);

namespace App\Application\Push;

use App\Models\Tenants\AccountProfile;
use Belluga\Events\Models\Tenants\Event;
use Belluga\PushHandler\Contracts\PushChannelAuthorizationContract;
use Belluga\PushHandler\Contracts\PushUserGatewayContract;
use Belluga\PushHandler\Models\Tenants\PushMessage;
use Illuminate\Validation\ValidationException;

class PushChannelAuthorizationService implements PushChannelAuthorizationContract
{
    public function __construct(
        private readonly PushUserGatewayContract $users,
    ) {}

    public function assertCanPersist(string $scope, ?string $accountId, array $audience): void
    {
        $type = trim((string) ($audience['type'] ?? ''));

        if ($type === 'users') {
            $this->assertDirectAudienceAllowed($scope, $accountId, $audience);

            return;
        }

        if ($type === 'all_users') {
            if ($scope !== 'tenant') {
                throw ValidationException::withMessages([
                    'audience.type' => 'The all_users channel is only publishable from tenant scope.',
                ]);
            }

            return;
        }

        if ($type === 'favorite_account_profile') {
            $this->assertFavoriteAccountProfileAllowed($scope, $accountId, $audience);

            return;
        }

        if ($type === 'event_confirmed') {
            $this->assertEventConfirmedAllowed($scope, $accountId, $audience);

            return;
        }

        throw ValidationException::withMessages([
            'audience.type' => 'This push audience is not supported.',
        ]);
    }

    public function assertCanDispatch(string $scope, ?string $accountId, PushMessage $message): void
    {
        $audience = is_array($message->audience ?? null) ? $message->audience : [];
        $effectiveAccountId = $scope === 'account'
            ? trim((string) ($accountId ?? $message->partner_id ?? ''))
            : null;

        $this->assertCanPersist($scope, $effectiveAccountId, $audience);
    }

    /**
     * @param  array<string, mixed>  $audience
     */
    private function assertDirectAudienceAllowed(string $scope, ?string $accountId, array $audience): void
    {
        $userIds = is_array($audience['user_ids'] ?? null) ? $audience['user_ids'] : [];
        $normalizedUserIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $userId): string => trim((string) $userId),
            $userIds,
        ), static fn (string $userId): bool => $userId !== '')));

        if (count($normalizedUserIds) !== 1) {
            throw ValidationException::withMessages([
                'audience.user_ids' => 'Direct delivery requires exactly one concrete user_id.',
            ]);
        }

        $userId = $normalizedUserIds[0];
        $user = $scope === 'account'
            ? $this->users->findUserForAccount((string) $accountId, $userId, null)
            : $this->users->findUserForTenant($userId, null);

        if ($user === null) {
            throw ValidationException::withMessages([
                'audience.user_ids' => 'Direct delivery target must resolve inside the allowed scope.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $audience
     */
    private function assertFavoriteAccountProfileAllowed(string $scope, ?string $accountId, array $audience): void
    {
        if ($scope !== 'account' || $accountId === null || $accountId === '') {
            throw ValidationException::withMessages([
                'audience.type' => 'favorite_account_profile is only publishable from account scope.',
            ]);
        }

        $accountProfileId = trim((string) ($audience['account_profile_id'] ?? ''));
        if ($accountProfileId === '') {
            throw ValidationException::withMessages([
                'audience.account_profile_id' => 'favorite_account_profile requires account_profile_id.',
            ]);
        }

        $profile = AccountProfile::query()->find($accountProfileId);
        if (! $profile instanceof AccountProfile || (string) $profile->account_id !== $accountId) {
            throw ValidationException::withMessages([
                'audience.account_profile_id' => 'Account scope is not allowed to publish to that profile channel.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $audience
     */
    private function assertEventConfirmedAllowed(string $scope, ?string $accountId, array $audience): void
    {
        if ($scope !== 'account' || $accountId === null || $accountId === '') {
            throw ValidationException::withMessages([
                'audience.type' => 'event_confirmed is only publishable from account scope.',
            ]);
        }

        $eventId = trim((string) ($audience['event_id'] ?? ''));
        if ($eventId === '') {
            throw ValidationException::withMessages([
                'audience.event_id' => 'event_confirmed requires event_id.',
            ]);
        }

        $event = Event::query()->find($eventId);
        if (! $event instanceof Event) {
            throw ValidationException::withMessages([
                'audience.event_id' => 'Event not found for event_confirmed audience.',
            ]);
        }

        $accountContextIds = collect($event->account_context_ids ?? [])
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        if (! in_array($accountId, $accountContextIds, true)) {
            throw ValidationException::withMessages([
                'audience.event_id' => 'Account scope is not allowed to publish to that event_confirmed channel.',
            ]);
        }
    }
}
