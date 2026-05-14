<?php

namespace App\Http\Api\v1\Controllers;

use App\Application\Branding\BrandingManifestService;
use App\Http\Controllers\Controller;
use Belluga\DeepLinks\Application\DeepLinkAssociationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class BrandingController extends Controller
{
    public function __construct(
        private readonly BrandingManifestService $brandingService,
        private readonly DeepLinkAssociationService $deepLinkAssociationService
    ) {}

    public function getManifest(Request $request): JsonResponse
    {
        $manifestData = $this->brandingService->buildManifest($request->host());

        return $this->withNoStoreHeaders(
            response()->json($manifestData)
                ->header('Content-Type', 'application/manifest+json')
        );
    }

    public function getLogoSettingsParameter(string $parameter): string
    {
        return $this->brandingService->resolveLogoSetting($parameter) ?? '';
    }

    public function getPwaIconParameter(string $parameter): string
    {
        return $this->brandingService->resolvePwaIcon($parameter) ?? '';
    }

    public function getFavicon(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse(
            $this->brandingService->resolveFaviconAsset($request->getHost()),
            $request
        );
    }

    public function getLogoLight(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse(
            $this->brandingService->resolveLogoSetting('light_logo_uri', $request->getHost()),
            $request
        );
    }

    public function getLogoDark(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse(
            $this->brandingService->resolveLogoSetting('dark_logo_uri', $request->getHost()),
            $request
        );
    }

    public function getMaskableIcon(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse(
            $this->brandingService->resolvePwaIcon('icon_maskable512_uri', $request->getHost()),
            $request
        );
    }

    public function getIcon192(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse(
            $this->brandingService->resolvePwaIcon('icon192_uri', $request->getHost()),
            $request
        );
    }

    public function getIcon512(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse(
            $this->brandingService->resolvePwaIcon('icon512_uri', $request->getHost()),
            $request
        );
    }

    public function getIconSource(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse(
            $this->brandingService->resolvePwaIcon('source_uri', $request->getHost()),
            $request
        );
    }

    public function getIconLight(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse(
            $this->brandingService->resolveLogoSetting('light_icon_uri', $request->getHost()),
            $request
        );
    }

    public function getIconDark(Request $request): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse(
            $this->brandingService->resolveLogoSetting('dark_icon_uri', $request->getHost()),
            $request
        );
    }

    public function getAssetLinks(): JsonResponse
    {
        return response()->json(
            $this->deepLinkAssociationService->buildAssetLinks()
        )->header('Content-Type', 'application/json');
    }

    public function getAppleAppSiteAssociation(): JsonResponse
    {
        return response()->json(
            $this->deepLinkAssociationService->buildAppleAppSiteAssociation()
        )->header('Content-Type', 'application/json');
    }

    private function brandingAssetResponse(?string $path, Request $request): Response|BinaryFileResponse
    {
        return $this->withNoStoreHeaders(
            $this->brandingService->assetResponse($path, $request->getHost())
        );
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    private function withNoStoreHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
