<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Telemetry\TelemetryEmitter;
use App\Application\Tenants\TenantDomainManagementService;
use App\Http\Api\v1\Requests\DomainStoreRequest;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Domains;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;

class DomainController extends Controller
{
    public function __construct(
        private readonly TenantDomainManagementService $domainService,
        private readonly TelemetryEmitter $telemetry
    ) {
    }

    public function store(DomainStoreRequest $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $domain = $this->domainService->create($tenant, $request->validated());

        $user = $request->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'domain_created',
                userId: (string) $user->_id,
                properties: [
                    'domain_id' => (string) $domain->_id,
                ],
                idempotencyKey: $request->header('X-Request-Id')
            );
        }

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

        $user = request()->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'domain_restored',
                userId: (string) $user->_id,
                properties: [
                    'domain_id' => (string) $domain->_id,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json([
            'data' => $this->transform($domain),
        ]);
    }

    public function destroy(\Illuminate\Http\Request $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $domainId = (string) $request->route('domain_id');
        $this->domainService->delete(
            $tenant,
            $domainId
        );

        $user = request()->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'domain_deleted',
                userId: (string) $user->_id,
                properties: [
                    'domain_id' => $domainId,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

        return response()->json();
    }

    public function forceDestroy(\Illuminate\Http\Request $request): JsonResponse
    {
        $tenant = Tenant::resolve();
        $domainId = (string) $request->route('domain_id');
        $this->domainService->forceDelete(
            $tenant,
            $domainId
        );

        $user = request()->user();
        if ($user) {
            $this->telemetry->emit(
                event: 'domain_force_deleted',
                userId: (string) $user->_id,
                properties: [
                    'domain_id' => $domainId,
                ],
                idempotencyKey: request()->header('X-Request-Id')
            );
        }

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
