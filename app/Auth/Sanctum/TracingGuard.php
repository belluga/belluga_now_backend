<?php

declare(strict_types=1);

namespace App\Auth\Sanctum;

use App\Application\Tenants\TenantRequestLifecycleTrace;
use App\Models\Landlord\LandlordUser;
use App\Models\Tenants\AccountUser;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\Guard;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\TransientToken;

final class TracingGuard extends Guard
{
    private const LAST_USED_AT_REFRESH_WINDOW_SECONDS = 60;

    public function __construct(
        AuthFactory $auth,
        private readonly TenantRequestLifecycleTrace $lifecycleTrace,
        mixed $expiration = null,
        ?string $provider = null,
    ) {
        parent::__construct($auth, $expiration, $provider);
    }

    public function __invoke(Request $request): mixed
    {
        foreach (Arr::wrap(config('sanctum.guard', 'web')) as $guard) {
            if ($user = $this->auth->guard($guard)->user()) {
                $this->lifecycleTrace->record('middleware.auth.session_guard.hit', [
                    'guard' => $guard,
                    'principal_kind' => $this->principalKind($user),
                ]);

                return $this->supportsTokens($user)
                    ? $user->withAccessToken(new TransientToken)
                    : $user;
            }
        }

        $token = $this->getTokenFromRequest($request);
        if (! is_string($token) || $token === '') {
            return null;
        }

        $this->lifecycleTrace->record('middleware.auth.token_lookup.start');

        $model = Sanctum::$personalAccessTokenModel;
        $accessToken = $model::findToken($token);

        $this->lifecycleTrace->record(
            $accessToken === null
                ? 'middleware.auth.token_lookup.missed'
                : 'middleware.auth.token_lookup.resolved',
            [
                'token_model' => class_basename($model),
                'tokenable_type' => $accessToken?->getAttribute('tokenable_type'),
            ]
        );

        if ($accessToken === null || ! $this->isAccessTokenWindowValid($accessToken)) {
            if ($accessToken !== null) {
                $this->lifecycleTrace->record('middleware.auth.token_lookup.rejected', [
                    'reason' => 'token_window_invalid',
                ]);
            }

            return null;
        }

        $this->lifecycleTrace->record('middleware.auth.principal_hydration.start', [
            'tokenable_type' => $accessToken->getAttribute('tokenable_type'),
        ]);

        $tokenable = $accessToken->tokenable;

        $this->lifecycleTrace->record('middleware.auth.principal_hydration.resolved', [
            'principal_kind' => $this->principalKind($tokenable),
        ]);

        if (! $this->hasValidProvider($tokenable) || ! $this->supportsTokens($tokenable)) {
            $this->lifecycleTrace->record('middleware.auth.token_lookup.rejected', [
                'reason' => 'provider_or_token_support_invalid',
                'principal_kind' => $this->principalKind($tokenable),
            ]);

            return null;
        }

        $isValid = true;
        if (is_callable(Sanctum::$accessTokenAuthenticationCallback)) {
            $isValid = (bool) (Sanctum::$accessTokenAuthenticationCallback)($accessToken, true);
        }

        if (! $isValid) {
            $this->lifecycleTrace->record('middleware.auth.token_lookup.rejected', [
                'reason' => 'authentication_callback_rejected',
                'principal_kind' => $this->principalKind($tokenable),
            ]);

            return null;
        }

        $this->lifecycleTrace->record('middleware.auth.current_token_binding.start', [
            'principal_kind' => $this->principalKind($tokenable),
        ]);

        $tokenable = $tokenable->withAccessToken($accessToken);

        $this->lifecycleTrace->record('middleware.auth.current_token_binding.passed', [
            'principal_kind' => $this->principalKind($tokenable),
        ]);

        event(new TokenAuthenticated($accessToken));

        $this->lifecycleTrace->record('middleware.auth.last_used_at.start', [
            'write_window_seconds' => self::LAST_USED_AT_REFRESH_WINDOW_SECONDS,
        ]);

        $writePerformed = $this->touchLastUsedAtIfDue($accessToken);

        $this->lifecycleTrace->record('middleware.auth.last_used_at.passed', [
            'write_performed' => $writePerformed,
        ]);

        return $tokenable;
    }

    private function isAccessTokenWindowValid(mixed $accessToken): bool
    {
        return (! $this->expiration || $accessToken->created_at->gt(now()->subMinutes($this->expiration)))
            && (! $accessToken->expires_at || ! $accessToken->expires_at->isPast());
    }

    private function touchLastUsedAtIfDue(mixed $accessToken): bool
    {
        $connection = $accessToken->getConnection();
        $now = now();
        $refreshBefore = $now->copy()->subSeconds(self::LAST_USED_AT_REFRESH_WINDOW_SECONDS);

        $update = function () use ($accessToken, $now, $refreshBefore): bool {
            $updated = $accessToken->newQuery()
                ->whereKey($accessToken->getKey())
                ->where(function ($query) use ($refreshBefore): void {
                    $query->whereNull('last_used_at')
                        ->orWhere('last_used_at', '<=', $refreshBefore);
                })
                ->update(['last_used_at' => $now]);

            if ($updated > 0) {
                $accessToken->forceFill(['last_used_at' => $now]);

                return true;
            }

            return false;
        };

        if (method_exists($connection, 'hasModifiedRecords')
            && method_exists($connection, 'setRecordModificationState')) {
            $result = false;

            tap($connection->hasModifiedRecords(), function ($hasModifiedRecords) use ($connection, $update, &$result): void {
                $result = $update();

                $connection->setRecordModificationState($hasModifiedRecords);
            });

            return $result;
        }

        return $update();
    }

    private function principalKind(mixed $principal): string
    {
        return match (true) {
            $principal instanceof AccountUser => 'tenant_account_user',
            $principal instanceof LandlordUser => 'landlord_user',
            $principal && in_array(HasApiTokens::class, class_uses_recursive($principal::class), true) => 'tokenable_other',
            $principal === null => 'anonymous',
            default => 'unknown',
        };
    }
}
