<?php

declare(strict_types=1);

namespace App\Application\LandlordUsers;

use App\Models\Landlord\PersonalAccessToken;
use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

class LandlordUserAccessService
{
    /**
     * @return array<int, string>
     */
    public function tenantAccessIds(LandlordUser $user): array
    {
        $tenantRoles = $user->tenant_roles ?? [];

        return collect($tenantRoles)
            ->pluck('tenant_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function permissions(LandlordUser $user, ?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::current();

        if ($tenant) {
            return $this->tenantPermissions($user, $tenant);
        }

        /** @var LandlordRole|null $role */
        $role = $user->landlordRole;

        return $role ? ($role->permissions ?? []) : [];
    }

    public function tokenAllows(LandlordUser $user, string $ability): bool
    {
        $permissions = $this->permissions($user);
        $parts = explode(':', $ability, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$resource, $action] = $parts;

        return in_array("$resource:*", $permissions, true)
            || in_array("$resource:$action", $permissions, true);
    }

    public function ensureEmail(LandlordUser $user, string $email): void
    {
        $email = Str::lower($email);
        $emails = $user->emails ?? [];

        if (! in_array($email, $emails, true)) {
            $emails[] = $email;
            $user->emails = array_values($emails);
            $user->save();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function credential(LandlordUser $user, string $provider, string $subject): ?array
    {
        $subject = Str::lower($subject);
        $credential = collect($user->credentials ?? [])
            ->first(static function (array $credential) use ($provider, $subject): bool {
                return ($credential['provider'] ?? null) === $provider
                    && ($credential['subject'] ?? null) === $subject;
            });

        return is_array($credential) ? $credential : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstCredentialForProvider(LandlordUser $user, string $provider): ?array
    {
        $credential = collect($user->credentials ?? [])
            ->first(static fn (array $credential): bool => ($credential['provider'] ?? null) === $provider);

        return is_array($credential) ? $credential : null;
    }

    public function firstPasswordCredentialHash(LandlordUser $user): ?string
    {
        $credential = $this->firstCredentialForProvider($user, 'password');
        $secretHash = $credential['secret_hash'] ?? null;

        return is_string($secretHash) && $secretHash !== '' ? $secretHash : null;
    }

    public function syncPasswordCredentialsForEmails(LandlordUser $user, string $secretHash): void
    {
        collect($user->emails ?? [])
            ->filter(static fn (mixed $email): bool => is_string($email) && $email !== '')
            ->map(static fn (string $email): string => Str::lower($email))
            ->unique()
            ->each(function (string $email) use ($user, $secretHash): void {
                $this->ensureEmail($user, $email);
                $this->syncCredential($user, 'password', $email, $secretHash);
            });
    }

    public function removeCredentialForSubject(LandlordUser $user, string $provider, string $subject): bool
    {
        $subject = Str::lower($subject);
        $credentials = collect($user->credentials ?? []);
        $filtered = $credentials
            ->reject(static function (array $credential) use ($provider, $subject): bool {
                return ($credential['provider'] ?? null) === $provider
                    && ($credential['subject'] ?? null) === $subject;
            })
            ->values();

        if ($filtered->count() === $credentials->count()) {
            return false;
        }

        $user->credentials = $filtered->all();
        $user->save();

        return true;
    }

    public function prunePasswordCredentialsOutsideCurrentEmails(LandlordUser $user): void
    {
        $currentEmails = collect($user->emails ?? [])
            ->filter(static fn (mixed $email): bool => is_string($email) && $email !== '')
            ->map(static fn (string $email): string => Str::lower($email))
            ->unique()
            ->values()
            ->all();

        $credentials = collect($user->credentials ?? []);
        $filtered = $credentials
            ->filter(static function (array $credential) use ($currentEmails): bool {
                if (($credential['provider'] ?? null) !== 'password') {
                    return true;
                }

                $subject = strtolower((string) ($credential['subject'] ?? ''));

                return $subject !== '' && in_array($subject, $currentEmails, true);
            })
            ->values();

        if ($filtered->count() === $credentials->count()) {
            return;
        }

        $user->credentials = $filtered->all();
        $user->save();
    }

    public function removeLegacyPasswordState(LandlordUser $user): void
    {
        $user->unset(['password', 'password_type']);
        $user->save();
    }

    public function revokeTokens(LandlordUser $user, ?string $deviceName = null): int
    {
        $userId = trim((string) $user->getKey());
        $query = PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where(function ($tokenQuery) use ($userId): void {
                $tokenQuery->where('tokenable_id', $userId);

                try {
                    $tokenQuery->orWhere('tokenable_id', new ObjectId($userId));
                } catch (\Throwable) {
                    // Some historical records persist string ids only.
                }
            });

        if (is_string($deviceName) && $deviceName !== '') {
            $query->where('name', $deviceName);
        }

        return $query->delete();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function syncCredential(
        LandlordUser $user,
        string $provider,
        string $subject,
        ?string $secretHash = null,
        array $metadata = []
    ): array {
        $subject = Str::lower($subject);
        $credentials = collect($user->credentials);

        $index = $credentials->search(static function (array $credential) use ($provider, $subject): bool {
            return ($credential['provider'] ?? null) === $provider
                && ($credential['subject'] ?? null) === $subject;
        });

        if ($index !== false) {
            $credential = $credentials->get($index);

            if ($secretHash !== null) {
                $credential['secret_hash'] = $secretHash;
            }

            if ($metadata !== []) {
                $credential['metadata'] = $metadata;
            }

            $credentials->put($index, $credential);
            $user->credentials = $credentials->values()->all();
            $user->save();

            return $user->credentials[$index];
        }

        $credential = [
            '_id' => (string) new ObjectId,
            'provider' => $provider,
            'subject' => $subject,
            'secret_hash' => $secretHash,
            'metadata' => $metadata,
            'linked_at' => Carbon::now(),
            'last_used_at' => null,
        ];

        $credentials->push($credential);
        $user->credentials = $credentials->values()->all();
        $user->save();

        return $credential;
    }

    /**
     * @return array<int, string>
     */
    private function tenantPermissions(LandlordUser $user, Tenant $tenant): array
    {
        return collect($user->tenant_roles)
            ->where('tenant_id', '==', (string) $tenant->_id)
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->values()
            ->all();
    }
}
