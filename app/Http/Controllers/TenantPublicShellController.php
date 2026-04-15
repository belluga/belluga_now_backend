<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PublicWeb\FlutterWebShellRenderer;
use App\Application\PublicWeb\PublicWebMetadataService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TenantPublicShellController extends Controller
{
    public function __construct(
        private readonly PublicWebMetadataService $metadataService,
        private readonly FlutterWebShellRenderer $shellRenderer,
    ) {}

    public function accountProfile(string $accountProfileSlug): Response
    {
        return $this->renderShell(
            $this->metadataService->accountProfileMetadata($accountProfileSlug)
        );
    }

    public function event(string $eventSlug): Response
    {
        return $this->renderShell(
            $this->metadataService->eventMetadata($eventSlug)
        );
    }

    public function staticAsset(string $assetRef): Response
    {
        return $this->renderShell(
            $this->metadataService->staticAssetMetadata($assetRef)
        );
    }

    public function fallback(Request $request, ?string $fallbackPath = null): Response
    {
        $requestedUri = trim((string) $request->getRequestUri());
        if ($requestedUri === '') {
            $requestedUri = $this->normalizeFallbackPath($fallbackPath);
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
}
