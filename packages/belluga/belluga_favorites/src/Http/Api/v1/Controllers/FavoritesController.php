<?php

declare(strict_types=1);

namespace Belluga\Favorites\Http\Api\v1\Controllers;

use Belluga\Favorites\Application\Favorites\FavoritesQueryService;
use Belluga\Favorites\Http\Api\v1\Requests\FavoritesIndexRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class FavoritesController extends Controller
{
    public function __construct(
        private readonly FavoritesQueryService $queryService,
    ) {}

    public function index(FavoritesIndexRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'items' => [],
                'has_more' => false,
            ]);
        }

        $identityState = data_get($user, 'identity_state');
        if (is_string($identityState) && $identityState === 'anonymous') {
            return response()->json([
                'items' => [],
                'has_more' => false,
            ]);
        }

        $validated = $request->validated();
        $payload = $this->queryService->listForOwner(
            ownerUserId: (string) $user->getAuthIdentifier(),
            page: (int) ($validated['page'] ?? 1),
            pageSize: (int) ($validated['page_size'] ?? 20),
            registryKey: isset($validated['registry_key']) ? (string) $validated['registry_key'] : null,
            targetType: isset($validated['target_type']) ? (string) $validated['target_type'] : null,
        );

        return response()->json([
            'items' => $payload['items'],
            'has_more' => $payload['has_more'],
        ]);
    }
}
