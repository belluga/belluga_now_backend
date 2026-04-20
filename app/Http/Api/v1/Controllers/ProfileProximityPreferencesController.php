<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\ProximityPreferences\ProximityPreferenceService;
use App\Http\Api\v1\Requests\ProfileProximityPreferencesUpsertRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\AccountUser;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ProfileProximityPreferencesController extends Controller
{
    public function __construct(
        private readonly ProximityPreferenceService $preferenceService,
    ) {}

    public function show(): JsonResponse
    {
        /** @var AccountUser $user */
        $user = request()->user();

        $preference = $this->preferenceService->findForUser($user);
        if ($preference === null) {
            return response()->json([
                'message' => 'Persisted proximity preferences were not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $this->preferenceService->toPayload($preference),
        ]);
    }

    public function upsert(ProfileProximityPreferencesUpsertRequest $request): JsonResponse
    {
        /** @var AccountUser $user */
        $user = $request->user();

        $preference = $this->preferenceService->upsertForUser(
            $user,
            $request->validated(),
        );

        return response()->json([
            'data' => $this->preferenceService->toPayload($preference),
        ]);
    }
}
