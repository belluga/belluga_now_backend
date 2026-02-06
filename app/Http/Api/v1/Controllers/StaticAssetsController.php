<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\StaticAssets\StaticAssetManagementService;
use App\Application\StaticAssets\StaticAssetMediaService;
use App\Application\StaticAssets\StaticAssetQueryService;
use App\Http\Api\v1\Requests\StaticAssetStoreRequest;
use App\Http\Api\v1\Requests\StaticAssetUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaticAssetsController extends Controller
{
    public function __construct(
        private readonly StaticAssetManagementService $assetService,
        private readonly StaticAssetMediaService $mediaService,
        private readonly StaticAssetQueryService $assetQueryService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15) ?: 15;

        $paginator = $this->assetQueryService->paginate(
            $request->query(),
            $request->boolean('archived'),
            $perPage
        );

        return response()->json($paginator->toArray());
    }

    public function store(StaticAssetStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        unset($validated['avatar'], $validated['cover']);
        $actor = $request->user();

        if ($actor) {
            $validated['created_by'] = (string) $actor->_id;
            $validated['created_by_type'] = $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant';
            $validated['updated_by'] = (string) $actor->_id;
            $validated['updated_by_type'] = $validated['created_by_type'];
        }

        $asset = $this->assetService->create($validated);
        $this->mediaService->applyUploads($request, $asset);

        return response()->json([
            'data' => $this->assetQueryService->format($asset),
        ], 201);
    }

    public function show(string $tenant_domain, string $asset_id): JsonResponse
    {
        $asset = $this->assetQueryService->findOrFail($asset_id);

        return response()->json([
            'data' => $this->assetQueryService->format($asset),
        ]);
    }

    public function showPublic(string $tenant_domain, string $asset_ref): JsonResponse
    {
        $asset = $this->assetQueryService->findByIdOrSlug($asset_ref);

        return response()->json([
            'data' => $this->assetQueryService->format($asset),
        ]);
    }

    public function update(StaticAssetUpdateRequest $request, string $tenant_domain, string $asset_id): JsonResponse
    {
        $asset = $this->assetQueryService->findOrFail($asset_id);

        $validated = $request->validated();
        unset($validated['avatar'], $validated['cover']);
        $actor = $request->user();
        if ($actor) {
            $validated['updated_by'] = (string) $actor->_id;
            $validated['updated_by_type'] = $actor instanceof \App\Models\Landlord\LandlordUser ? 'landlord' : 'tenant';
        }

        $updated = $this->assetService->update($asset, $validated);
        $this->mediaService->applyUploads($request, $updated);

        return response()->json([
            'data' => $this->assetQueryService->format($updated),
        ]);
    }

    public function destroy(string $tenant_domain, string $asset_id): JsonResponse
    {
        $asset = $this->assetQueryService->findOrFail($asset_id);
        $this->assetService->delete($asset);

        return response()->json();
    }

    public function restore(string $tenant_domain, string $asset_id): JsonResponse
    {
        $asset = $this->assetQueryService->findOrFail($asset_id, true);
        $restored = $this->assetService->restore($asset);

        return response()->json([
            'data' => $this->assetQueryService->format($restored),
        ]);
    }

    public function forceDestroy(string $tenant_domain, string $asset_id): JsonResponse
    {
        $asset = $this->assetQueryService->findOrFail($asset_id, true);
        $this->assetService->forceDelete($asset);

        return response()->json();
    }
}
