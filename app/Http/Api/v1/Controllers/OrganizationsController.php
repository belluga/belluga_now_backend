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
    ) {}

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
            'data' => $this->organizationQueryService->format($organization),
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $organization = $this->organizationQueryService->findByIdOrFail($this->routeOrganizationId($request));

        return response()->json([
            'data' => $this->organizationQueryService->format($organization),
        ]);
    }

    public function update(OrganizationUpdateRequest $request): JsonResponse
    {
        $organization = $this->organizationQueryService->findByIdOrFail($this->routeOrganizationId($request));
        $updated = $this->organizationService->update($organization, $request->validated());

        return response()->json([
            'data' => $this->organizationQueryService->format($updated),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $organization = $this->organizationQueryService->findByIdOrFail($this->routeOrganizationId($request));
        $this->organizationService->delete($organization);

        return response()->json();
    }

    public function restore(Request $request): JsonResponse
    {
        $organization = $this->organizationQueryService->findByIdOrFail($this->routeOrganizationId($request), true);
        $restored = $this->organizationService->restore($organization);

        return response()->json([
            'data' => $this->organizationQueryService->format($restored),
        ]);
    }

    public function forceDestroy(Request $request): JsonResponse
    {
        $organization = $this->organizationQueryService->findByIdOrFail($this->routeOrganizationId($request), true);
        $this->organizationService->forceDelete($organization);

        return response()->json();
    }

    private function routeOrganizationId(Request $request): string
    {
        $organizationId = trim((string) $request->route('organization_id'));
        abort_if($organizationId === '', 404);

        return $organizationId;
    }
}
