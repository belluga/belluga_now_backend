<?php

use App\Http\Api\v1\Controllers\AccountProfilesController;
use App\Http\Api\v1\Controllers\StaticAssetsController;
use App\Http\Middleware\CheckTenantAccess;
use Belluga\Events\Http\Api\v1\Controllers\AgendaController;
use Belluga\Events\Http\Api\v1\Controllers\EventsController;
use Belluga\Events\Http\Api\v1\Controllers\EventStreamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () {
        Route::get('/agenda', [AgendaController::class, 'index']);
        Route::get('/events', [EventsController::class, 'index']);
        Route::get('/events/stream', [EventStreamController::class, 'stream']);
        Route::get('/events/{event_id}', [EventsController::class, 'show']);
        Route::get('/account_profiles', [AccountProfilesController::class, 'publicIndex']);
        Route::get('/static_assets/{asset_ref}', [StaticAssetsController::class, 'showPublic']);
    });
