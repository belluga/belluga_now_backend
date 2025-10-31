<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Branding\BrandingManifestService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingController extends Controller
{
    public function __construct(
        private readonly BrandingManifestService $brandingService
    ) {
    }

    public function getManifest(Request $request): JsonResponse
    {
        $manifestData = $this->brandingService->buildManifest($request->host());

        return response()->json($manifestData)
            ->header('Content-Type', 'application/manifest+json');
    }

    public function getLogoSettingsParameter(String $parameter): String {
        return $this->brandingService->resolveLogoSetting($parameter) ?? '';
    }

    public function getPwaIconParameter(String $parameter): String {
        return $this->brandingService->resolvePwaIcon($parameter) ?? '';
    }

    public function getFavicon(): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse($this->getLogoSettingsParameter('favicon_uri'));
    }

    public function getLogoLight(): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse($this->getLogoSettingsParameter('light_logo_uri'));
    }

    public function getLogoDark(): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse($this->getLogoSettingsParameter('dark_logo_uri'));
    }

    public function getMaskableIcon(): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse($this->getPwaIconParameter('icon_maskable512_uri'));
    }

    public function getIcon192(): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse($this->getPwaIconParameter('icon192_uri'));
    }

    public function getIcon512(): Response|BinaryFileResponse
    {
        return $this->brandingService->assetResponse($this->getPwaIconParameter('icon512_uri'));
    }
}
