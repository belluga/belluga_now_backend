<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Environment\EnvironmentResolverService;
use App\Http\Api\v1\Requests\EnvironmentRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EnvironmentController extends Controller
{
    public function __construct(
        private readonly EnvironmentResolverService $environmentService
    ) {
    }

    public function showEnvironmentData(EnvironmentRequest $request): JsonResponse
    {
        $resolved = $this->environmentService->resolve([
            ...$request->validated(),
            'request_root' => $request->root(),
        ]);

        return response()->json($resolved);
    }
}
