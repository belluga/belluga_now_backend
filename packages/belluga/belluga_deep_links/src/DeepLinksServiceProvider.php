<?php

declare(strict_types=1);

namespace Belluga\DeepLinks;

use Belluga\DeepLinks\Application\DeepLinkAssociationService;
use Belluga\DeepLinks\Application\DeferredDeepLinkResolverService;
use Belluga\DeepLinks\Application\WebToAppPromotionService;
use Belluga\DeepLinks\Contracts\AppLinksIdentifierGatewayContract;
use Belluga\DeepLinks\Contracts\AppLinksSettingsSourceContract;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class DeepLinksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->ensureHostBinding(AppLinksIdentifierGatewayContract::class);
        $this->ensureHostBinding(AppLinksSettingsSourceContract::class);

        $this->app->singleton(DeepLinkAssociationService::class);
        $this->app->singleton(WebToAppPromotionService::class);
        $this->app->singleton(DeferredDeepLinkResolverService::class);
    }

    private function ensureHostBinding(string $abstract): void
    {
        if ($this->app->bound($abstract)) {
            return;
        }

        $this->app->bind($abstract, static function () use ($abstract) {
            throw new RuntimeException("belluga_deep_links host binding missing for [{$abstract}]");
        });
    }
}
