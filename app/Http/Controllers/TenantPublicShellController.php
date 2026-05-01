<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PublicWeb\FlutterWebShellRenderer;
use App\Application\PublicWeb\PublicWebMetadataService;
use Belluga\DeepLinks\Application\WebToAppPromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TenantPublicShellController extends Controller
{
    public function __construct(
        private readonly PublicWebMetadataService $metadataService,
        private readonly FlutterWebShellRenderer $shellRenderer,
        private readonly WebToAppPromotionService $promotionService,
    ) {}

    public function accountProfile(
        Request $request,
        string $accountProfileSlug,
    ): Response|RedirectResponse {
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $this->requestTargetPath($request, '/parceiro/'.$accountProfileSlug),
        );
        if ($redirect !== null) {
            return $redirect;
        }

        return $this->renderShell(
            $this->metadataService->accountProfileMetadata($accountProfileSlug)
        );
    }

    public function event(
        Request $request,
        string $eventSlug,
    ): Response|RedirectResponse {
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $this->requestTargetPath($request, '/agenda/evento/'.$eventSlug),
        );
        if ($redirect !== null) {
            return $redirect;
        }

        return $this->renderShell(
            $this->metadataService->eventMetadata($eventSlug)
        );
    }

    public function staticAsset(
        Request $request,
        string $assetRef,
    ): Response|RedirectResponse {
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $this->requestTargetPath($request, '/static/'.$assetRef),
        );
        if ($redirect !== null) {
            return $redirect;
        }

        return $this->renderShell(
            $this->metadataService->staticAssetMetadata($assetRef)
        );
    }

    public function fallback(
        Request $request,
        ?string $fallbackPath = null,
    ): Response|RedirectResponse {
        $requestedUri = $this->requestTargetPath($request, $fallbackPath);
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $requestedUri
        );
        if ($redirect !== null) {
            return $redirect;
        }

        return $this->renderShell(
            $this->metadataService->defaultMetadata($requestedUri)
        );
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function renderShell(array $metadata): Response
    {
        return response(
            $this->shellRenderer->render($metadata),
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
            ]
        );
    }

    private function normalizeFallbackPath(?string $fallbackPath): string
    {
        $trimmed = trim((string) $fallbackPath);
        if ($trimmed === '') {
            return '/';
        }

        return '/'.ltrim($trimmed, '/');
    }

    private function requestTargetPath(
        Request $request,
        ?string $fallbackPath,
    ): string {
        $requestedUri = trim((string) $request->getRequestUri());
        if ($requestedUri !== '') {
            return $requestedUri;
        }

        return $this->normalizeFallbackPath($fallbackPath);
    }

    private function redirectToInstalledAppIfAndroid(
        Request $request,
        string $targetPath,
    ): ?RedirectResponse {
        if (
            $this->promotionService->detectPlatformTarget($request->userAgent())
            !== 'android'
        ) {
            return null;
        }

        if ($this->isPromotionBoundaryPath($targetPath)) {
            return null;
        }

        return redirect()->to('/open-app?'.http_build_query([
            'path' => $this->promotionService->normalizeTargetPath($targetPath),
            'store_channel' => 'web_direct',
            'platform_target' => 'android',
            'fallback' => 'promotion',
        ]));
    }

    private function isPromotionBoundaryPath(string $targetPath): bool
    {
        $parts = parse_url($targetPath);
        $path = is_array($parts)
            ? (string) ($parts['path'] ?? '/')
            : $targetPath;

        return rtrim($path, '/') === '/baixe-o-app';
    }
}
