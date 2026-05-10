<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\PersonalAccessToken;

class CheckUserAccess
{
    public const ACCOUNT_SCOPED_AUTH_CONTEXT_KEY = 'accountScopedAuthorization';

    protected string $current_account_id {
        get {
            return Context::get('accountId');
        }
    }

    protected string $current_tenant_id {
        get {
            return Context::get('tenantId');
        }
    }

    protected $user {
        get {
            return auth('sanctum')->user();
        }
    }

    protected bool $have_access = false;

    public function handle($request, Closure $next)
    {
        if (! $this->user) {
            throw new AuthenticationException;
        }

        switch (get_class($this->user)) {
            case \App\Models\Landlord\LandlordUser::class:
                $this->have_access = $this->checkUserAccess($this->current_tenant_id);
                break;
            case \App\Models\Tenants\AccountUser::class:
                $this->have_access = $this->checkUserAccess($this->current_account_id);
                if ($this->have_access) {
                    $this->assertAccountUserTokenMatchesCurrentAccount($request);
                }
                break;
        }

        if (! $this->have_access) {
            throw new AuthenticationException;
        }

        Context::add(self::ACCOUNT_SCOPED_AUTH_CONTEXT_KEY, true);

        try {
            return $next($request);
        } finally {
            Context::forget(self::ACCOUNT_SCOPED_AUTH_CONTEXT_KEY);
        }
    }

    protected function checkUserAccess(string $checkId): bool
    {
        $checkId = trim($checkId);

        return $checkId !== '' && in_array($checkId, $this->user->getAccessToIds(), true);
    }

    private function assertAccountUserTokenMatchesCurrentAccount($request): void
    {
        if (! $request->bearerToken()) {
            return;
        }

        $token = $this->user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return;
        }

        $tokenAccountId = trim((string) ($token->getAttribute('account_id') ?? ''));
        if ($tokenAccountId === '') {
            throw new AuthorizationException('Account token is not bound to an account.');
        }

        $currentAccountId = trim((string) $this->current_account_id);
        if ($currentAccountId === '' || ! hash_equals($tokenAccountId, $currentAccountId)) {
            throw new AuthorizationException('Account token is not valid for the current account.');
        }
    }
}
