<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Auth\TenantScopedAccessTokenService;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AnonymousIdentityService
{
    public function __construct(
        private readonly TenantScopedAccessTokenService $tenantScopedAccessTokenService,
    ) {}

    /**
     * @param array{
     *     device_name: string,
     *     fingerprint: array{
     *         hash: string,
     *         user_agent?: string|null,
     *         locale?: string|null
     *     },
     *     metadata?: array<string, mixed>
     * } $payload
     */
    public function register(Tenant $tenant, array $payload): AnonymousIdentityResult
    {
        $fingerprint = $payload['fingerprint'];
        $hash = $fingerprint['hash'];
        $now = Carbon::now();

        $user = AccountUser::where('fingerprints.hash', $hash)->first();

        if ($user === null) {
            $user = $this->createAnonymousUser($hash, $payload, $now);
        } else {
            $this->updateFingerprint($user, $payload, $now);
        }

        if ($user->first_seen_at === null || $now->lessThan($user->first_seen_at)) {
            $user->first_seen_at = $now;
            $user->save();
        }

        $policy = $tenant->anonymous_access_policy ?? [];
        $abilities = $policy['abilities'] ?? [];

        if (in_array('*', $abilities, true)) {
            Log::warning('Wildcard abilities rejected for anonymous identity token.', [
                'abilities' => $abilities,
                'tenant_id' => (string) $tenant->_id,
            ]);
            $abilities = array_values(array_filter($abilities, static fn (string $ability): bool => $ability !== '*'));
        }

        $token = $this->tenantScopedAccessTokenService->issueForAccountUser(
            $user,
            'anonymous:'.$payload['device_name'],
            $abilities,
            (string) $tenant->_id
        );
        $plainToken = $token->plainTextToken;

        $expiresAt = null;
        if (isset($policy['token_ttl_minutes'])) {
            $tokenTtl = (int) $policy['token_ttl_minutes'];
            $accessToken = $token->accessToken;
            $accessToken->expires_at = $now->copy()->addMinutes($tokenTtl);
            $accessToken->save();
            $expiresAt = $accessToken->expires_at;
        }

        return new AnonymousIdentityResult(
            $user->fresh(),
            $plainToken,
            $abilities,
            $expiresAt
        );
    }

    /**
     * @param array{
     *     device_name: string,
     *     fingerprint: array{
     *         hash: string,
     *         user_agent?: string|null,
     *         locale?: string|null
     *     },
     *     metadata?: array<string, mixed>
     * } $payload
     */
    private function createAnonymousUser(string $hash, array $payload, Carbon $now): AccountUser
    {
        $fingerprint = $payload['fingerprint'];

        return AccountUser::create([
            'identity_state' => 'anonymous',
            'first_seen_at' => $now,
            'fingerprints' => [
                [
                    'hash' => $hash,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'user_agent' => $fingerprint['user_agent'] ?? null,
                    'locale' => $fingerprint['locale'] ?? null,
                    'metadata' => $payload['metadata'] ?? [],
                ],
            ],
            'credentials' => [],
            'consents' => [],
        ]);
    }

    /**
     * @param array{
     *     device_name: string,
     *     fingerprint: array{
     *         hash: string,
     *         user_agent?: string|null,
     *         locale?: string|null
     *     },
     *     metadata?: array<string, mixed>
     * } $payload
     */
    private function updateFingerprint(AccountUser $user, array $payload, Carbon $now): void
    {
        $fingerprint = $payload['fingerprint'];
        $hash = $fingerprint['hash'];

        $fingerprints = $user->fingerprints ?? [];
        $index = null;

        foreach ($fingerprints as $i => $existing) {
            if (($existing['hash'] ?? null) === $hash) {
                $index = $i;
                break;
            }
        }

        $fingerprintPayload = [
            'hash' => $hash,
            'last_seen_at' => $now,
            'user_agent' => $fingerprint['user_agent'] ?? null,
        ];

        if (isset($fingerprint['locale'])) {
            $fingerprintPayload['locale'] = $fingerprint['locale'];
        }

        if (isset($payload['metadata'])) {
            $fingerprintPayload['metadata'] = $payload['metadata'];
        }

        if ($index !== null) {
            $existing = $fingerprints[$index];
            $fingerprintPayload['first_seen_at'] = $existing['first_seen_at'] ?? $now;
            $fingerprints[$index] = array_replace($existing, $fingerprintPayload);
        } else {
            $fingerprintPayload['first_seen_at'] = $now;
            $fingerprints[] = $fingerprintPayload;
        }

        $user->fingerprints = array_values($fingerprints);
        $user->save();
    }
}
