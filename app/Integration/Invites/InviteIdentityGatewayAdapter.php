<?php

declare(strict_types=1);

namespace App\Integration\Invites;

use App\Models\Tenants\AccountProfile;
use App\Models\Tenants\AccountUser;
use Belluga\Invites\Contracts\InviteIdentityGatewayContract;
use Belluga\Invites\Support\InviteDomainException;
use Illuminate\Support\Collection;

class InviteIdentityGatewayAdapter implements InviteIdentityGatewayContract
{
    public function resolveInviterPrincipal(mixed $user, ?string $accountProfileId): array
    {
        $accountUser = $this->requireAccountUser($user);

        if ($accountProfileId === null || trim($accountProfileId) === '') {
            $userId = $this->accountUserId($accountUser);

            return [
                'principal' => [
                    'kind' => 'user',
                    'id' => $userId,
                ],
                'issued_by_user_id' => $userId,
                'account_profile_id' => null,
                'display_name' => $this->userDisplayName($accountUser),
                'avatar_url' => null,
            ];
        }

        /** @var AccountProfile|null $profile */
        $profile = AccountProfile::query()->find($accountProfileId);
        if (! $profile) {
            throw new InviteDomainException('account_profile_not_found', 404);
        }

        if (! in_array((string) $profile->account_id, $accountUser->getAccessToIds(), true)) {
            throw new InviteDomainException('inviter_not_allowed', 403);
        }

        return [
            'principal' => [
                'kind' => 'account_profile',
                'id' => (string) $profile->getAttribute('_id'),
            ],
            'issued_by_user_id' => $this->accountUserId($accountUser),
            'account_profile_id' => (string) $profile->getAttribute('_id'),
            'display_name' => $this->profileDisplayName($profile),
            'avatar_url' => $this->nullableString($profile->avatar_url),
        ];
    }

    public function resolveUserRecipient(string $userId): ?array
    {
        /** @var AccountUser|null $user */
        $user = AccountUser::query()->find($userId);
        if (! $user || ! $user->isActive()) {
            return null;
        }

        return [
            'user_id' => (string) $user->getAttribute('_id'),
            'display_name' => $this->userDisplayName($user),
            'avatar_url' => null,
        ];
    }

    public function matchImportedContacts(array $contacts, mixed $ownerUser, ?string $saltVersion): array
    {
        $this->requireAccountUser($ownerUser);

        /** @var Collection<int, array{type:string,hash:string}> $contactsCollection */
        $contactsCollection = collect($contacts)
            ->filter(static function (array $contact): bool {
                return in_array((string) ($contact['type'] ?? ''), ['email', 'phone'], true)
                    && trim((string) ($contact['hash'] ?? '')) !== '';
            })
            ->values();

        if ($contactsCollection->isEmpty()) {
            return [];
        }

        $emails = $contactsCollection
            ->where('type', 'email')
            ->pluck('hash')
            ->all();
        $phones = $contactsCollection
            ->where('type', 'phone')
            ->pluck('hash')
            ->all();

        $matches = [];

        AccountUser::query()->get()->each(function (AccountUser $user) use (&$matches, $emails, $phones): void {
            foreach ((array) ($user->emails ?? []) as $email) {
                $hash = $this->hashEmail((string) $email);
                if ($hash !== null && in_array($hash, $emails, true)) {
                    $matches[$hash] = [
                        'contact_hash' => $hash,
                        'type' => 'email',
                        'user_id' => (string) $user->getAttribute('_id'),
                        'display_name' => $this->userDisplayName($user),
                        'avatar_url' => null,
                    ];
                }
            }

            foreach ((array) ($user->phones ?? []) as $phone) {
                $hash = $this->hashPhone((string) $phone);
                if ($hash !== null && in_array($hash, $phones, true)) {
                    $matches[$hash] = [
                        'contact_hash' => $hash,
                        'type' => 'phone',
                        'user_id' => (string) $user->getAttribute('_id'),
                        'display_name' => $this->userDisplayName($user),
                        'avatar_url' => null,
                    ];
                }
            }
        });

        return $matches;
    }

    private function requireAccountUser(mixed $user): AccountUser
    {
        if ($user instanceof AccountUser) {
            return $user;
        }

        throw new InviteDomainException('auth_required', 401);
    }

    private function userDisplayName(AccountUser $user): ?string
    {
        $name = $this->nullableString($user->name);
        if ($name !== null) {
            return $name;
        }

        $email = collect((array) ($user->emails ?? []))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->first(static fn (string $value): bool => $value !== '');

        return $email !== null && $email !== '' ? $email : null;
    }

    private function profileDisplayName(AccountProfile $profile): ?string
    {
        return $this->nullableString($profile->display_name)
            ?? $this->nullableString($profile->slug);
    }

    private function hashEmail(string $email): ?string
    {
        $normalized = mb_strtolower(trim($email));

        return $normalized === '' ? null : hash('sha256', $normalized);
    }

    private function hashPhone(string $phone): ?string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';

        return $normalized === '' ? null : hash('sha256', $normalized);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function accountUserId(AccountUser $user): string
    {
        $id = $user->getKey();
        if ($id === null) {
            $id = $user->_id ?? $user->getAttribute('_id') ?? $user->getAuthIdentifier();
        }

        return (string) $id;
    }
}
