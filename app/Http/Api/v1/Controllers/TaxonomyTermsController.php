<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Taxonomies\TaxonomyTermManagementService;
use App\Http\Api\v1\Requests\TaxonomyTermStoreRequest;
use App\Http\Api\v1\Requests\TaxonomyTermUpdateRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxonomyTermsController extends Controller
{
    public function __construct(
        private readonly TaxonomyTermManagementService $managementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $taxonomyId = (string) $request->route('taxonomy_id', '');

        return response()->json([
            'data' => $this->managementService->list($taxonomyId),
        ]);
    }

    public function store(TaxonomyTermStoreRequest $request): JsonResponse
    {
        $taxonomyId = (string) $request->route('taxonomy_id', '');
        $term = $this->managementService->create($taxonomyId, $request->validated());

        return response()->json(['data' => $term], 201);
    }

    public function update(TaxonomyTermUpdateRequest $request): JsonResponse
    {
        $taxonomyId = (string) $request->route('taxonomy_id', '');
        $termId = (string) $request->route('term_id', '');
        $term = $this->managementService->update($taxonomyId, $termId, $request->validated());

        return response()->json(['data' => $term]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $taxonomyId = (string) $request->route('taxonomy_id', '');
        $termId = (string) $request->route('term_id', '');
        $this->managementService->delete($taxonomyId, $termId);

        return response()->json([]);
    }
}
