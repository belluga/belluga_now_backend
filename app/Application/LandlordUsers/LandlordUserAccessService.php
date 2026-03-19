<?php

declare(strict_types=1);

namespace App\Application\LandlordUsers;

use App\Models\Landlord\LandlordRole;
use App\Models\Landlord\LandlordUser;
use App\Models\Landlord\Tenant;
use Illuminate\Support\Carbon;
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
        $emails = $user->emails ?? [];

        if (! in_array($email, $emails, true)) {
            $emails[] = $email;
            $user->emails = array_values($emails);
            $user->save();
        }
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
