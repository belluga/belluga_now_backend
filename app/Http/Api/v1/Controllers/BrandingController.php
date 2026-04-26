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

    public function getFavicon(): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse($this->brandingService->resolveFaviconAsset());
    }

    public function getLogoLight(): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse($this->getLogoSettingsParameter('light_logo_uri'));
    }

    public function getLogoDark(): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse($this->getLogoSettingsParameter('dark_logo_uri'));
    }

    public function getMaskableIcon(): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse($this->getPwaIconParameter('icon_maskable512_uri'));
    }

    public function getIcon192(): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse($this->getPwaIconParameter('icon192_uri'));
    }

    public function getIcon512(): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse($this->getPwaIconParameter('icon512_uri'));
    }

    public function getIconSource(): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse($this->getPwaIconParameter('source_uri'));
    }

    public function getIconLight(): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse($this->getLogoSettingsParameter('light_icon_uri'));
    }

    public function getIconDark(): Response|BinaryFileResponse
    {
        return $this->brandingAssetResponse($this->getLogoSettingsParameter('dark_icon_uri'));
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

    private function brandingAssetResponse(?string $path): Response|BinaryFileResponse
    {
        return $this->withNoStoreHeaders(
            $this->brandingService->assetResponse($path)
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
