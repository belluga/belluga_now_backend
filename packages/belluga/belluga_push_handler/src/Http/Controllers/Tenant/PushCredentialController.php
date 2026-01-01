<?php

declare(strict_types=1);

namespace Belluga\PushHandler\Http\Controllers\Tenant;

use Belluga\PushHandler\Http\Requests\PushCredentialRequest;
use Belluga\PushHandler\Models\Tenants\PushCredential;
use Illuminate\Http\JsonResponse;

class PushCredentialController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PushCredential::query()->get(),
        ]);
    }

    public function store(PushCredentialRequest $request): JsonResponse
    {
        $credential = PushCredential::create($request->validated());

        return response()->json(['data' => $credential], 201);
    }

    public function update(PushCredentialRequest $request, string $credential_id): JsonResponse
    {
        $credential = PushCredential::query()->findOrFail($credential_id);
        $credential->fill($request->validated());
        $credential->save();

        return response()->json(['data' => $credential]);
    }

    public function destroy(string $credential_id): JsonResponse
    {
        $credential = PushCredential::query()->findOrFail($credential_id);
        $credential->delete();

        return response()->json(['ok' => true]);
    }
}
