<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\AnonymousIdentityRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use MongoDB\BSON\ObjectId;

class AnonymousIdentityController extends Controller
{
    public function store(AnonymousIdentityRequest $request): JsonResponse
    {
        $tenant = Tenant::current();

        if (! $tenant) {
            abort(404, 'Tenant not resolved for anonymous identity issuance.');
        }

        $validated = $request->validated();
        $fingerprint = $validated['fingerprint'];
        $hash = $fingerprint['hash'];
        $now = Carbon::now();

        $user = AccountUser::where('fingerprints.hash', $hash)->first();

        if (! $user) {
            $user = AccountUser::create([
                'identity_state' => 'anonymous',
                'first_seen_at' => $now,
                'fingerprints' => [
                    [
                        'hash' => $hash,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'user_agent' => $fingerprint['user_agent'] ?? $request->userAgent(),
                        'locale' => $fingerprint['locale'] ?? null,
                        'metadata' => $validated['metadata'] ?? [],
                    ]
                ],
                'credentials' => [],
                'consents' => [],
            ]);
        } else {
            $fingerprints = $user->fingerprints ?? [];

            $index = null;
            foreach ($fingerprints as $i => $fp) {
                if (($fp['hash'] ?? null) === $hash) {
                    $index = $i;
                    break;
                }
            }

            $payload = [
                'hash' => $hash,
                'last_seen_at' => $now,
                'user_agent' => $fingerprint['user_agent'] ?? $request->userAgent(),
            ];
            if (isset($fingerprint['locale'])) {
                $payload['locale'] = $fingerprint['locale'];
            }
            if (isset($validated['metadata'])) {
                $payload['metadata'] = $validated['metadata'];
            }

            if ($index !== null) {
                $existing = $fingerprints[$index];
                $payload['first_seen_at'] = $existing['first_seen_at'] ?? $now;
                $fingerprints[$index] = array_replace($existing, $payload);
            } else {
                $payload['first_seen_at'] = $now;
                $fingerprints[] = $payload;
            }

            $user->fingerprints = array_values($fingerprints);
            $user->save();
        }

        if ($user->first_seen_at === null || $now->lessThan($user->first_seen_at)) {
            $user->first_seen_at = $now;
            $user->save();
        }

        $policy = $tenant->anonymous_access_policy ?? [];
        $abilities = $policy['abilities'] ?? [];

        $token = $user->createToken('anonymous:' . $validated['device_name'], $abilities);
        $plainToken = $token->plainTextToken;

        $expiresAtIso = null;
        if (isset($policy['token_ttl_minutes'])) {
            $minutes = (int) $policy['token_ttl_minutes'];
            $accessToken = $token->accessToken;
            $accessToken->expires_at = $now->copy()->addMinutes($minutes);
            $accessToken->save();
            $expiresAtIso = $accessToken->expires_at?->toISOString();
        }

        $response = [
            'data' => [
                'user_id' => (string) $user->_id,
                'identity_state' => $user->identity_state,
                'token' => $plainToken,
                'abilities' => $abilities,
            ],
        ];

        if ($expiresAtIso) {
            $response['data']['expires_at'] = $expiresAtIso;
        }

        return response()->json($response, 201);
    }
}

