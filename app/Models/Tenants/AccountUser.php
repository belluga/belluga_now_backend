<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Application\Accounts\AccountUserAccessService;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
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
        'first_seen_at',
        'registered_at',
        'password',
        'identity_state',
        'fingerprints',
        'credentials',
        'consents',
        'promotion_audit',
        'merged_source_ids',
        'devices',
        'version',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'registered_at' => 'datetime',
        'password' => 'hashed'
    ];

    protected static function booted(): void
    {
        static::creating(function (AccountUser $user): void {
            $now = Carbon::now();
            $user->identity_state ??= 'anonymous';
            $user->fingerprints ??= [];
            $user->credentials ??= [];
            $user->consents ??= [];
            $user->emails ??= [];
            $user->phones ??= [];
            $user->account_roles ??= [];
            $user->merged_source_ids ??= [];
            $user->promotion_audit ??= [];
            $user->devices ??= [];
            $user->first_seen_at ??= $now;
            $user->version ??= 1;

            if ($user->isRegisteredState() && $user->registered_at === null) {
                $user->registered_at = $now;
            }
        });

        static::updating(function (AccountUser $user): void {
            if ($user->isRegisteredState() && $user->registered_at === null) {
                $user->registered_at = Carbon::now();
            }
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
        return $this->accessService()->accountAccessIds($this);
    }

    public function getPermissions(?Account $account = null): array
    {
        return $this->accessService()->permissions($this, $account);
    }

    public function tokenCan(string $ability): bool
    {
        $token = $this->currentAccessToken();

        if ($token) {
            return $token->can($ability);
        }

        return $this->accessService()->tokenAllows($this, $ability);
    }

    public function syncCredential(string $provider, string $subject, ?string $secretHash = null, array $metadata = []): array
    {
        return $this->accessService()->syncCredential($this, $provider, $subject, $secretHash, $metadata);
    }

    public function removeCredentialById(string $credentialId): bool
    {
        return $this->accessService()->removeCredential($this, $credentialId);
    }

    public function hasCredential(string $provider, string $subject): bool
    {
        return $this->accessService()->hasCredential($this, $provider, $subject);
    }

    public function ensureEmail(string $email): void
    {
        $this->accessService()->ensureEmail($this, $email);
    }

    private function accessService(): AccountUserAccessService
    {
        return app(AccountUserAccessService::class);
    }

    private function isRegisteredState(): bool
    {
        return in_array($this->identity_state, ['registered', 'validated'], true);
    }

}
