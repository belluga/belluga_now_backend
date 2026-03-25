<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use Belluga\MapPois\Http\Api\v1\Controllers\MapPoisController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function (): void {
        Route::get('/map/pois', [MapPoisController::class, 'index']);
        Route::get('/map/pois/lookup', [MapPoisController::class, 'lookup']);
        Route::get('/map/near', [MapPoisController::class, 'near']);
        Route::get('/map/filters', [MapPoisController::class, 'filters']);
    });
