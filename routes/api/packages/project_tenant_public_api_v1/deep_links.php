<?php

declare(strict_types=1);

use Belluga\DeepLinks\Http\Api\v1\Controllers\DeferredDeepLinkResolverController;
use Illuminate\Support\Facades\Route;

Route::prefix('deep-links')
    ->group(function (): void {
        Route::post('/deferred/resolve', [DeferredDeepLinkResolverController::class, 'resolve']);
    });
