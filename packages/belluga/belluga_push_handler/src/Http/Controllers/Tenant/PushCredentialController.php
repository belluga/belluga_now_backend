<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Exceptions\MultiplePushCredentialsException;
use Belluga\PushHandler\Http\Requests\PushCredentialRequest;
use Belluga\PushHandler\Models\Tenants\PushCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;

class PushCredentialController
{
    public function index(): JsonResponse
    {
        $credentials = PushCredential::query()->get();

        if ($credentials->count() > 1) {
            return response()->json([
                'message' => (new MultiplePushCredentialsException($credentials->count()))->getMessage(),
            ], 409);
        }

        return response()->json([
            'data' => $credentials,
        ]);
    }

    public function upsert(PushCredentialRequest $request): JsonResponse
    {
        $credentials = PushCredential::query()->get();

        if ($credentials->count() > 1) {
            return response()->json([
                'message' => (new MultiplePushCredentialsException($credentials->count()))->getMessage(),
            ], 409);
        }

        $payload = $request->validated();
        $credential = $credentials->first();
        $status = 200;

        if (! $credential) {
            $credential = PushCredential::create($payload);
            $status = 201;
        } else {
            PushCredential::query()
                ->whereKey($credential->getKey())
                ->update([
                    'project_id' => $payload['project_id'],
                    'client_email' => $payload['client_email'],
                    'private_key' => Crypt::encryptString($payload['private_key']),
                    'updated_at' => now(),
                ]);

            $credential = PushCredential::query()->findOrFail($credential->getKey());
        }

        return response()->json(['data' => $credential], $status);
    }
}
