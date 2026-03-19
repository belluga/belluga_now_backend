<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Taxonomies\TaxonomyManagementService;
use App\Application\Taxonomies\TaxonomyQueryService;
use App\Http\Api\v1\Requests\TaxonomyStoreRequest;
use App\Http\Api\v1\Requests\TaxonomyUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxonomiesController extends Controller
{
    public function __construct(
        private readonly TaxonomyQueryService $queryService,
        private readonly TaxonomyManagementService $managementService,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->queryService->list(),
        ]);
    }

    public function store(TaxonomyStoreRequest $request): JsonResponse
    {
        $taxonomy = $this->managementService->create($request->validated());

        return response()->json(['data' => $taxonomy], 201);
    }

    public function update(TaxonomyUpdateRequest $request): JsonResponse
    {
        $taxonomyId = (string) $request->route('taxonomy_id', '');
        $taxonomy = $this->managementService->update($taxonomyId, $request->validated());

        return response()->json(['data' => $taxonomy]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $taxonomyId = (string) $request->route('taxonomy_id', '');
        $this->managementService->delete($taxonomyId);

        return response()->json([]);
    }
}
