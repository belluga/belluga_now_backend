<?php

declare(strict_types=1);

namespace App\Http\Api\v1\Controllers;

use App\Application\Initialization\InitializationPayload;
use App\Application\Initialization\SystemInitializationService;
use App\Http\Api\v1\Requests\InitializeRequest;
use App\Http\Controllers\Controller;
use App\Support\Branding\BrandingAssetManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class InitializationController extends Controller
{
    public function __construct(
        private readonly SystemInitializationService $initializationService,
        private readonly BrandingAssetManager $brandingAssetManager
    ) {}

    public function isInitialized(): JsonResponse
    {

        if ($this->initializationService->isInitialized()) {
            return response()->json(
                [
                    'message' => 'Sistema já inicializado',
                    'errors' => [
                        'user' => ['Sistema já inicializado'],
                    ]],
                200);
        }

        return response()->json(status: 403);
    }

    public function initialize(InitializeRequest $request): JsonResponse
    {
        if ($this->initializationService->isInitialized()) {
            return response()->json(['success' => false, 'message' => 'System already initialized.'], 403);
        }

        $validated = $request->validated();
        $brandingAssets = $this->brandingAssetManager->createBrandingPayload($request);

        $payload = new InitializationPayload(
            landlord: $validated['landlord'],
            tenant: Arr::except($validated['tenant'], ['domains']),
            role: $validated['role'],
            user: $validated['user'],
            themeDataSettings: $validated['branding_data']['theme_data_settings'],
            logoSettings: $brandingAssets['logo_settings'],
            pwaIcon: $brandingAssets['pwa_icon'],
            tenantDomains: $validated['tenant']['domains'] ?? []
        );

        $result = $this->initializationService->initialize($payload);

        return response()->json([
            'data' => $result->toResponsePayload(),
        ], 201);
    }
}
