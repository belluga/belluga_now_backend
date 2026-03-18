<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers\TestSupport;

use App\Application\TestSupport\Invites\InviteStageTestSupportService;
use App\Http\Controllers\Controller;
use App\Models\Landlord\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InviteStageTestSupportController extends Controller
{
    public function __construct(
        private readonly InviteStageTestSupportService $service,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'scenario' => ['required', 'string'],
        ]);

        return response()->json(
            $this->service->bootstrap(
                Tenant::resolve(),
                (string) $validated['run_id'],
                (string) $validated['scenario'],
            )
        );
    }

    public function state(string $tenant_domain, string $run_id): JsonResponse
    {
        return response()->json($this->service->state(Tenant::resolve(), $run_id));
    }

    public function cleanup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[a-zA-Z0-9._-]+$/'],
        ]);

        return response()->json($this->service->cleanup(Tenant::resolve(), (string) $validated['run_id']));
    }
}
