<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use MongoDB\BSON\ObjectId;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\EmbedsMany;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class AccountUser extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;
    use SoftDeletes;
    use DocumentModel;
    use UsesTenantConnection;

    protected $table = 'account_users';

    protected $fillable = [
        'name',
        'emails',
        'phones',
        'password',
        'identity_state',
        'fingerprints',
        'account_assignments',
        'credentials',
        'consents',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed'
    ];

    protected static function booted(): void
    {
        static::creating(function (AccountUser $user): void {
            $user->identity_state ??= 'anonymous';
            $user->fingerprints ??= [];
            $user->account_assignments ??= [];
            $user->credentials ??= [];
            $user->consents ??= [];
            $user->emails ??= [];
            $user->phones ??= [];
        });
    }

    public function accountRoles(): EmbedsMany
    {
        return $this->embedsMany(AccountRole::class, 'account_roles');
    }

    public function haveAccessTo(Account $account): bool
    {
        return in_array($account->id, $this->getAccessToIds(), true);
    }

    public function isActive(): bool
    {
        return $this->deleted_at === null;
    }

    public function getAccessToIds(): array
    {
        return collect($this->account_roles)
            ->pluck('account_id')
            ->toArray() ?? [];
    }

    public function getPermissions(?Account $account = null): array
    {
        $account = $account ?? Account::current();

        if (! $account) {
            throw new AuthenticationException();
        }

        return collect($this->account_roles)
            ->where('account_id', '==', $account->id)
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->toArray();
    }

    public function tokenCan(string $ability): bool
    {
        $permissions = $this->getPermissions();

        $parts = explode(':', $ability, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$resource, $action] = $parts;

        return in_array('*', $permissions, true)
            || in_array("$resource:*", $permissions, true)
            || in_array("$resource:$action", $permissions, true);
    }

    public function syncCredential(string $provider, string $subject, ?string $secretHash = null, array $metadata = []): array
    {
        $credentials = collect($this->credentials);

        $index = $credentials->search(static function (array $credential) use ($provider, $subject): bool {
            return ($credential['provider'] ?? null) === $provider
                && ($credential['subject'] ?? null) === $subject;
        });

        if ($index !== false) {
            $credential = $credentials->get($index);
            if ($secretHash !== null) {
                $credential['secret_hash'] = $secretHash;
            }
            if (! empty($metadata)) {
                $credential['metadata'] = $metadata;
            }
            $credentials->put($index, $credential);
            $this->credentials = $credentials->values()->all();
            $this->save();

            return $this->credentials[$index];
        }

        $credential = [
            '_id' => (string) new ObjectId(),
            'provider' => $provider,
            'subject' => $subject,
            'secret_hash' => $secretHash,
            'metadata' => $metadata,
            'linked_at' => Carbon::now(),
            'last_used_at' => null,
        ];

        $credentials->push($credential);
        $this->credentials = $credentials->values()->all();
        $this->save();

        return $credential;
    }

    public function removeCredentialById(string $credentialId): bool
    {
        $credentials = collect($this->credentials);

        $filtered = $credentials->reject(static function (array $credential) use ($credentialId): bool {
            $currentId = $credential['_id'] ?? $credential['id'] ?? null;
            return $currentId === $credentialId;
        })->values();

        if ($filtered->count() === $credentials->count()) {
            return false;
        }

        $this->credentials = $filtered->all();
        $this->save();

        return true;
    }

    public function hasCredential(string $provider, string $subject): bool
    {
        return collect($this->credentials)->contains(static function (array $credential) use ($provider, $subject): bool {
            return ($credential['provider'] ?? null) === $provider
                && ($credential['subject'] ?? null) === $subject;
        });
    }

    public function ensureEmail(string $email): void
    {
        $emails = $this->emails ?? [];

        if (! in_array($email, $emails, true)) {
            $emails[] = $email;
            $this->emails = array_values($emails);
            $this->save();
        }
    }

}
