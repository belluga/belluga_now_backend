<?php

declare(strict_types=1);

namespace Belluga\Favorites\Http\Api\v1\Controllers;

use Belluga\Favorites\Application\Favorites\FavoritesCommandService;
use Belluga\Favorites\Application\Favorites\FavoritesQueryService;
use Belluga\Favorites\Http\Api\v1\Requests\FavoritesIndexRequest;
use Belluga\Favorites\Http\Api\v1\Requests\FavoritesMutateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class FavoritesController extends Controller
{
    private readonly FavoritesQueryService $queryService;

    private readonly FavoritesCommandService $commandService;

    public function __construct(
        FavoritesQueryService $queryService,
        FavoritesCommandService $commandService,
    ) {
        $this->queryService = $queryService;
        $this->commandService = $commandService;
    }

    public function index(FavoritesIndexRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
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

    public function store(FavoritesMutateRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validated();
        $selector = $this->commandService->favorite(
            ownerUserId: (string) $user->getAuthIdentifier(),
            targetId: (string) $validated['target_id'],
            registryKey: isset($validated['registry_key']) ? (string) $validated['registry_key'] : null,
            targetType: isset($validated['target_type']) ? (string) $validated['target_type'] : null,
        );

        if (! is_array($selector)) {
            return response()->json([
                'message' => 'Invalid favorites registry or target type.',
            ], 422);
        }

        return response()->json([
            ...$selector,
            'is_favorite' => true,
        ]);
    }

    public function destroy(FavoritesMutateRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validated();
        $selector = $this->commandService->unfavorite(
            ownerUserId: (string) $user->getAuthIdentifier(),
            targetId: (string) $validated['target_id'],
            registryKey: isset($validated['registry_key']) ? (string) $validated['registry_key'] : null,
            targetType: isset($validated['target_type']) ? (string) $validated['target_type'] : null,
        );

        if (! is_array($selector)) {
            return response()->json([
                'message' => 'Invalid favorites registry or target type.',
            ], 422);
        }

        return response()->json([
            ...$selector,
            'is_favorite' => false,
        ]);
    }
}
