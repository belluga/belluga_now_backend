<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Http\Api\v1\Requests\AnonymousIdentityRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AnonymousIdentityController extends Controller
{
    public function store(AnonymousIdentityRequest ): JsonResponse
    {
         = Tenant::current();

        if (! ) {
            abort(404, 'Tenant not resolved for anonymous identity issuance.');
        }

         = ->validated();
         = ['fingerprint'];
         = ['hash'];
         = Carbon::now();

         = AccountUser::where('anonymous_fingerprint.hash', )->first();

        if (! ) {
             = AccountUser::create([
                'identity_state' => 'anonymous',
                'anonymous_fingerprint' => [
                    'hash' => ,
                    'first_seen_at' => ,
                    'last_seen_at' => ,
                    'user_agent' => ['user_agent'] ?? ->userAgent(),
                    'locale' => ['locale'] ?? null,
                    'metadata' => ['metadata'] ?? [],
                ],
                'account_assignments' => [],
                'credentials' => [],
                'consents' => [],
            ]);
        } else {
             = ->anonymous_fingerprint ?? [];
            ['last_seen_at'] = ;
            ['user_agent'] = ['user_agent'] ?? ->userAgent();

            if (isset(['locale'])) {
                ['locale'] = ['locale'];
            }

            if (isset(['metadata'])) {
                ['metadata'] = ['metadata'];
            }

            ['first_seen_at'] = ['first_seen_at'] ?? ;
            ->anonymous_fingerprint = ;
            ->save();
        }

         = ->anonymous_access_policy ?? [];
         = ['abilities'] ?? [];

         = ->createToken('anonymous:' . ['device_name'], );
         = ->plainTextToken;

         = null;
        if (isset(['token_ttl_minutes'])) {
             = (int) ['token_ttl_minutes'];
             = ->accessToken;
            ->expires_at = ->copy()->addMinutes();
            ->save();
             = ->expires_at?->toISOString();
        }

         = [
            'data' => [
                'user_id' => (string) ->_id,
                'identity_state' => ->identity_state,
                'token' => ,
                'abilities' => ,
            ],
        ];

        if () {
            ['data']['expires_at'] = ;
        }

        return response()->json(, 201);
    }
}
