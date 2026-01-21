<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Tenants\TenantDomainManagementService;
use App\Http\Api\v1\Requests\DomainStoreRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class DomainController extends Controller
{
    public function __construct(
        private readonly TenantDomainManagementService $domainService
    ) {
    }

    public function store(DomainStoreRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $domain = $this->domainService->create($tenant, $request->validated());

        return response()->json([
            'data' => $this->transform($domain),
        ], 201);
    }

    public function restore(\Illuminate\Http\Request $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $domain = $this->domainService->restore(
            $tenant,
            (string) $request->route('domain_id')
        );

        return response()->json([
            'data' => $this->transform($domain),
        ]);
    }

    public function destroy(\Illuminate\Http\Request $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $this->domainService->delete(
            $tenant,
            (string) $request->route('domain_id')
        );

        return response()->json();
    }

    public function forceDestroy(\Illuminate\Http\Request $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $this->domainService->forceDelete(
            $tenant,
            (string) $request->route('domain_id')
        );

        return response()->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Domains $domain): array
    {
        return [
            'id' => (string) $domain->_id,
            'path' => $domain->path,
            'type' => $domain->type,
            'created_at' => $domain->created_at?->toJSON(),
            'updated_at' => $domain->updated_at?->toJSON(),
            'deleted_at' => $domain->deleted_at?->toJSON(),
        ];
    }
}
