<?php

declare(strict_types=1);

namespace App\Models\Tenants;

use App\Application\Accounts\AccountUserAccessService;
use App\Application\Auth\TenantScopedAccessTokenService;
use App\Http\Middleware\CheckUserAccess;
use Closure;
use DateTimeInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use MongoDB\Laravel\Eloquent\DocumentModel;
use MongoDB\Laravel\Eloquent\SoftDeletes;
use MongoDB\Laravel\Relations\EmbedsMany;
use RuntimeException;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class AccountUser extends Authenticatable
{
    use DocumentModel;
    use HasApiTokens {
        createToken as private createSanctumToken;
    }
    use Notifiable;
    use SoftDeletes;
    use UsesTenantConnection;

    private const ACCOUNT_SCOPED_TOKEN_ISSUER_CONTEXT_KEY = 'accountUser.validatedAccountScopedTokenIssuerContext';

    protected $table = 'account_users';

    protected $fillable = [
        'name',
        'emails',
        'email_hashes',
        'phones',
        'phone_hashes',
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
        'timezone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'registered_at' => 'datetime',
        'password' => 'hashed',
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
            $user->email_hashes ??= [];
            $user->phones ??= [];
            $user->phone_hashes ??= [];
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

        static::saving(function (AccountUser $user): void {
            $user->emails ??= [];
            $user->phones ??= [];
            $user->email_hashes = self::hashEmails((array) $user->emails);
            $user->phone_hashes = self::hashPhones((array) $user->phones);
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
        $accountIds = collect([$account->id, $account->_id])
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        return collect($this->getAccessToIds())->contains(
            static fn (string $accessId): bool => collect($accountIds)->contains(
                static fn (string $accountId): bool => hash_equals($accountId, $accessId)
            )
        );
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

    /**
     * Create a new personal access token for the user.
     *
     * @param  array<int, string>  $abilities
     */
    public function createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)
    {
        if (TenantScopedAccessTokenService::containsAccountScopedAbility($abilities)) {
            $this->assertValidatedAccountScopedTokenIssuerContext($abilities);
        }

        return $this->createSanctumToken($name, $abilities, $expiresAt);
    }

    /**
     * @param  array<int, string>  $abilities
     * @param  Closure(): NewAccessToken  $issuer
     */
    public static function withValidatedAccountScopedTokenIssuerContext(
        self $user,
        string $accountId,
        array $abilities,
        Closure $issuer
    ): NewAccessToken {
        self::assertValidatedAccountScopedTokenIssuerCaller();

        $accountId = trim($accountId);
        if ($accountId === '') {
            throw new RuntimeException('Cannot create account-scoped account token without validated account context.');
        }

        if (! $user->hasAccessToAccountId($accountId)) {
            throw new RuntimeException('Cannot create account-scoped account token for inaccessible account context.');
        }

        $previousContext = Context::get(self::ACCOUNT_SCOPED_TOKEN_ISSUER_CONTEXT_KEY);
        Context::add(self::ACCOUNT_SCOPED_TOKEN_ISSUER_CONTEXT_KEY, self::accountScopedTokenIssuerContext(
            $user,
            $accountId,
            $abilities
        ));

        try {
            return $issuer();
        } finally {
            if ($previousContext === null) {
                Context::forget(self::ACCOUNT_SCOPED_TOKEN_ISSUER_CONTEXT_KEY);
            } else {
                Context::add(self::ACCOUNT_SCOPED_TOKEN_ISSUER_CONTEXT_KEY, $previousContext);
            }
        }
    }

    private static function assertValidatedAccountScopedTokenIssuerCaller(): void
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach (array_slice($frames, 1) as $frame) {
            $callerClass = $frame['class'] ?? null;
            if (is_string($callerClass) && hash_equals(TenantScopedAccessTokenService::class, $callerClass)) {
                return;
            }
        }

        throw new RuntimeException(
            'Account-scoped AccountUser token issuer context may only be opened by TenantScopedAccessTokenService.'
        );
    }

    public function tokenCan(string $ability): bool
    {
        $token = $this->currentAccessToken();

        if (! $token) {
            return $this->accessService()->tokenAllows($this, $ability);
        }

        if (! $this->tokenAbilityCeilingAllows($token, $ability)) {
            return false;
        }

        if (! $this->isPersistedPersonalAccessToken($token)) {
            return true;
        }

        if (! $this->shouldRevalidateAccountScopedPermissions()) {
            return true;
        }

        try {
            return $this->accessService()->tokenAllows($this, $ability);
        } catch (AuthenticationException) {
            return false;
        }
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

    /**
     * @param  array<int, string>  $abilities
     */
    private function assertValidatedAccountScopedTokenIssuerContext(array $abilities): void
    {
        $context = Context::get(self::ACCOUNT_SCOPED_TOKEN_ISSUER_CONTEXT_KEY);
        if (! is_array($context)) {
            throw new RuntimeException('Account-scoped AccountUser tokens must be issued through a validated account token issuer context.');
        }

        $userId = trim((string) $this->getKey());
        $contextUserId = trim((string) ($context['user_id'] ?? ''));
        $contextAccountId = trim((string) ($context['account_id'] ?? ''));
        $contextAbilitiesHash = trim((string) ($context['abilities_hash'] ?? ''));
        $expectedAbilitiesHash = self::accountScopedAbilitiesHash($abilities);

        if (
            $userId === ''
            || $contextUserId === ''
            || $contextAccountId === ''
            || $contextAbilitiesHash === ''
            || ! hash_equals($userId, $contextUserId)
            || ! hash_equals($expectedAbilitiesHash, $contextAbilitiesHash)
            || ! $this->hasAccessToAccountId($contextAccountId)
        ) {
            throw new RuntimeException('Account-scoped AccountUser token issuer context does not match the token request.');
        }
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array{user_id: string, account_id: string, abilities_hash: string}
     */
    private static function accountScopedTokenIssuerContext(self $user, string $accountId, array $abilities): array
    {
        return [
            'user_id' => trim((string) $user->getKey()),
            'account_id' => trim($accountId),
            'abilities_hash' => self::accountScopedAbilitiesHash($abilities),
        ];
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private static function accountScopedAbilitiesHash(array $abilities): string
    {
        $normalizedAbilities = collect($abilities)
            ->map(static fn (mixed $ability): string => trim((string) $ability))
            ->filter(static fn (string $ability): bool => $ability !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return hash('sha256', implode("\n", $normalizedAbilities));
    }

    private function hasAccessToAccountId(string $accountId): bool
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            return false;
        }

        foreach ($this->getAccessToIds() as $accessId) {
            $accessId = trim((string) $accessId);
            if ($accessId !== '' && hash_equals($accessId, $accountId)) {
                return true;
            }
        }

        return false;
    }

    private function shouldRevalidateAccountScopedPermissions(): bool
    {
        return Account::current() !== null
            && Context::get(CheckUserAccess::ACCOUNT_SCOPED_AUTH_CONTEXT_KEY) === true;
    }

    private function tokenAbilityCeilingAllows(mixed $token, string $ability): bool
    {
        if (! $this->isPersistedPersonalAccessToken($token)) {
            return $token->can($ability);
        }

        return $this->accessService()->abilityListAllows((array) $token->abilities, $ability);
    }

    private function isPersistedPersonalAccessToken(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken && $token->exists === true;
    }

    private function isRegisteredState(): bool
    {
        return in_array($this->identity_state, ['registered', 'validated'], true);
    }

    /**
     * @param  array<int, mixed>  $emails
     * @return array<int, string>
     */
    private static function hashEmails(array $emails): array
    {
        $hashes = [];
        foreach ($emails as $email) {
            $normalized = mb_strtolower(trim((string) $email));
            if ($normalized === '') {
                continue;
            }
            $hashes[$normalized] = hash('sha256', $normalized);
        }

        return array_values($hashes);
    }

    /**
     * @param  array<int, mixed>  $phones
     * @return array<int, string>
     */
    private static function hashPhones(array $phones): array
    {
        $hashes = [];
        foreach ($phones as $phone) {
            $normalized = preg_replace('/\D+/', '', (string) $phone) ?? '';
            if ($normalized === '') {
                continue;
            }
            $hashes[$normalized] = hash('sha256', $normalized);
        }

        return array_values($hashes);
    }
}
