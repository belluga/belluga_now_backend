<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Organizations\OrganizationManagementService;
use App\Application\Organizations\OrganizationQueryService;
use App\Http\Api\v1\Requests\OrganizationStoreRequest;
use App\Http\Api\v1\Requests\OrganizationUpdateRequest;
use App\Http\Controllers\Controller;
use App\Models\Tenants\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationsController extends Controller
{
    public function __construct(
        private readonly OrganizationManagementService $organizationService,
        private readonly OrganizationQueryService $organizationQueryService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15) ?: 15;

        $paginator = $this->organizationQueryService->paginate(
            $request->query(),
            $request->boolean('archived'),
            $perPage
        );

        return response()->json($paginator->toArray());
    }

    public function store(OrganizationStoreRequest $request): JsonResponse
    {
        $organization = $this->organizationService->create($request->validated());

        return response()->json([
            'data' => $organization,
        ], 201);
    }

    public function show(string $organizationId): JsonResponse
    {
        $organization = Organization::query()->where('_id', $organizationId)->firstOrFail();

        return response()->json([
            'data' => $organization,
        ]);
    }

    public function update(OrganizationUpdateRequest $request, string $organizationId): JsonResponse
    {
        $organization = Organization::query()->where('_id', $organizationId)->firstOrFail();
        $updated = $this->organizationService->update($organization, $request->validated());

        return response()->json([
            'data' => $updated,
        ]);
    }

    public function destroy(string $organizationId): JsonResponse
    {
        $organization = Organization::query()->where('_id', $organizationId)->firstOrFail();
        $this->organizationService->delete($organization);

        return response()->json();
    }

    public function restore(string $organizationId): JsonResponse
    {
        $organization = Organization::onlyTrashed()->where('_id', $organizationId)->firstOrFail();
        $restored = $this->organizationService->restore($organization);

        return response()->json([
            'data' => $restored,
        ]);
    }

    public function forceDestroy(string $organizationId): JsonResponse
    {
        $organization = Organization::onlyTrashed()->where('_id', $organizationId)->firstOrFail();
        $this->organizationService->forceDelete($organization);

        return response()->json();
    }
}
