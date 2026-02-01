<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Organizations\OrganizationManagementService;
use App\Application\Organizations\OrganizationQueryService;
use App\Http\Api\v1\Requests\OrganizationStoreRequest;
use App\Http\Api\v1\Requests\OrganizationUpdateRequest;
use App\Http\Controllers\Controller;
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
        $organization = $this->organizationQueryService->findByIdOrFail($organizationId);

        return response()->json([
            'data' => $this->organizationQueryService->format($organization),
        ]);
    }

    public function update(OrganizationUpdateRequest $request, string $organizationId): JsonResponse
    {
        $organization = $this->organizationQueryService->findByIdOrFail($organizationId);
        $updated = $this->organizationService->update($organization, $request->validated());

        return response()->json([
            'data' => $this->organizationQueryService->format($updated),
        ]);
    }

    public function destroy(string $organizationId): JsonResponse
    {
        $organization = $this->organizationQueryService->findByIdOrFail($organizationId);
        $this->organizationService->delete($organization);

        return response()->json();
    }

    public function restore(string $organizationId): JsonResponse
    {
        $organization = $this->organizationQueryService->findByIdOrFail($organizationId, true);
        $restored = $this->organizationService->restore($organization);

        return response()->json([
            'data' => $this->organizationQueryService->format($restored),
        ]);
    }

    public function forceDestroy(string $organizationId): JsonResponse
    {
        $organization = $this->organizationQueryService->findByIdOrFail($organizationId, true);
        $this->organizationService->forceDelete($organization);

        return response()->json();
    }
}
