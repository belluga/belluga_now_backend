<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\AccountProfiles\AccountProfileRegistryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AccountProfileTypesController extends Controller
{
    public function __construct(
        private readonly AccountProfileRegistryService $registryService,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->registryService->registry(),
        ]);
    }
}
