<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PublicWeb\FlutterWebShellRenderer;
use App\Application\PublicWeb\PublicWebMetadataService;
use App\Application\Tenants\TenantRequestLifecycleTrace;
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
        $targetPath = $this->requestTargetPath($request, '/parceiro/'.$accountProfileSlug);
        $consumeDirectFallbackBypass = $this->shouldConsumeDirectFallbackBypass(
            $request,
            $targetPath,
        );
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $targetPath,
            $consumeDirectFallbackBypass,
        );
        if ($redirect !== null) {
            return $redirect;
        }

        app(TenantRequestLifecycleTrace::class)->record('endpoint.public_shell.controller.enter', [
            'route_kind' => 'account_profile',
        ]);

        return $this->renderShell(
            $this->metadataService->accountProfileMetadata($accountProfileSlug),
            forgetDirectFallbackBypass: $consumeDirectFallbackBypass,
        );
    }

    public function event(
        Request $request,
        string $eventSlug,
    ): Response|RedirectResponse {
        $targetPath = $this->requestTargetPath($request, '/agenda/evento/'.$eventSlug);
        $consumeDirectFallbackBypass = $this->shouldConsumeDirectFallbackBypass(
            $request,
            $targetPath,
        );
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $targetPath,
            $consumeDirectFallbackBypass,
        );
        if ($redirect !== null) {
            return $redirect;
        }

        app(TenantRequestLifecycleTrace::class)->record('endpoint.public_shell.controller.enter', [
            'route_kind' => 'event',
        ]);

        return $this->renderShell(
            $this->metadataService->eventMetadata($eventSlug),
            forgetDirectFallbackBypass: $consumeDirectFallbackBypass,
        );
    }

    public function staticAsset(
        Request $request,
        string $assetRef,
    ): Response|RedirectResponse {
        $targetPath = $this->requestTargetPath($request, '/static/'.$assetRef);
        $consumeDirectFallbackBypass = $this->shouldConsumeDirectFallbackBypass(
            $request,
            $targetPath,
        );
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $targetPath,
            $consumeDirectFallbackBypass,
        );
        if ($redirect !== null) {
            return $redirect;
        }

        app(TenantRequestLifecycleTrace::class)->record('endpoint.public_shell.controller.enter', [
            'route_kind' => 'static_asset',
        ]);

        return $this->renderShell(
            $this->metadataService->staticAssetMetadata($assetRef),
            forgetDirectFallbackBypass: $consumeDirectFallbackBypass,
        );
    }

    public function fallback(
        Request $request,
        ?string $fallbackPath = null,
    ): Response|RedirectResponse {
        $requestedUri = $this->requestTargetPath($request, $fallbackPath);
        $consumeDirectFallbackBypass = $this->shouldConsumeDirectFallbackBypass(
            $request,
            $requestedUri,
        );
        $redirect = $this->redirectToInstalledAppIfAndroid(
            $request,
            $requestedUri,
            $consumeDirectFallbackBypass,
        );
        if ($redirect !== null) {
            return $redirect;
        }

        if ($this->isInvitePath($requestedUri)) {
            app(TenantRequestLifecycleTrace::class)->record('endpoint.public_shell.controller.enter', [
                'route_kind' => 'invite',
            ]);

            return $this->renderShell(
                $this->metadataService->inviteMetadata($request->query('code')),
                forgetDirectFallbackBypass: $consumeDirectFallbackBypass,
            );
        }

        app(TenantRequestLifecycleTrace::class)->record('endpoint.public_shell.controller.enter', [
            'route_kind' => 'fallback',
        ]);

        return $this->renderShell(
            $this->metadataService->defaultMetadata($requestedUri),
            forgetDirectFallbackBypass: $consumeDirectFallbackBypass,
        );
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function renderShell(
        array $metadata,
        bool $forgetDirectFallbackBypass = false,
    ): Response {
        app(TenantRequestLifecycleTrace::class)->record('endpoint.public_shell.response_ready');

        $response = response(
            $this->shellRenderer->render($metadata),
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
            ]
        );

        if ($forgetDirectFallbackBypass) {
            $response->cookie(
                cookie()->forget(
                    WebToAppPromotionService::WEB_DIRECT_FALLBACK_BYPASS_COOKIE
                )
            );
        }

        return $response;
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
        bool $suppressDirectHandoff = false,
    ): ?RedirectResponse {
        if (
            $suppressDirectHandoff
            || $this->shouldSkipDirectAndroidHandoffForKnownInAppBrowser($request)
        ) {
            return null;
        }

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
            'fallback' => 'target',
        ]));
    }

    private function shouldSkipDirectAndroidHandoffForKnownInAppBrowser(
        Request $request,
    ): bool {
        if ($this->promotionService->detectPlatformTarget($request->userAgent()) !== 'android') {
            return false;
        }

        $userAgent = strtolower(trim((string) $request->userAgent()));
        if ($userAgent === '') {
            return false;
        }

        foreach (['instagram', 'fban', 'fbav', 'fb_iab', 'messenger'] as $marker) {
            if (str_contains($userAgent, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function shouldConsumeDirectFallbackBypass(
        Request $request,
        string $targetPath,
    ): bool {
        $cookieTargetPath = trim((string) $request->cookie(
            WebToAppPromotionService::WEB_DIRECT_FALLBACK_BYPASS_COOKIE
        ));

        if ($cookieTargetPath === '') {
            return false;
        }

        return $cookieTargetPath === $this->promotionService
            ->normalizeTargetPath($targetPath);
    }

    private function isPromotionBoundaryPath(string $targetPath): bool
    {
        $parts = parse_url($targetPath);
        $path = is_array($parts)
            ? (string) ($parts['path'] ?? '/')
            : $targetPath;

        return rtrim($path, '/') === '/baixe-o-app';
    }

    private function isInvitePath(string $targetPath): bool
    {
        $parts = parse_url($targetPath);
        $path = is_array($parts)
            ? (string) ($parts['path'] ?? '/')
            : $targetPath;

        return rtrim($path, '/') === '/invite';
    }
}
