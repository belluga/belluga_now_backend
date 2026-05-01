<?php

declare(strict_types=1);

namespace Belluga\DeepLinks\Http\Web\Controllers;

use Belluga\DeepLinks\Application\WebToAppPromotionService;
use Belluga\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class OpenAppRedirectController extends Controller
{
    public function __construct(
        private readonly WebToAppPromotionService $promotionService,
        private readonly AppLinksSettingsSourceContract $settingsSource,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $targetPath = $this->promotionService->normalizeTargetPath($request->query('path'));
        $code = $this->promotionService->normalizeCode($request->query('code'));
        $storeChannel = $this->promotionService->normalizeStoreChannel($request->query('store_channel'));
        $preferPromotionFallback = $this->promotionService
            ->prefersPromotionFallback($request->query('fallback'));
        $platformTarget = $this->promotionService->normalizePlatformTarget($request->query('platform_target'))
            ?? $this->promotionService->detectPlatformTarget($request->userAgent());

        $redirectUrl = $this->promotionService->resolveRedirectUrl(
            origin: $request->getSchemeAndHttpHost(),
            platformTarget: $platformTarget,
            targetPath: $targetPath,
            code: $code,
            storeChannel: $storeChannel,
            settings: $this->settingsSource->currentAppLinksSettings(),
            preferPromotionFallback: $preferPromotionFallback,
        );

        return redirect()->away($redirectUrl);
    }
}
