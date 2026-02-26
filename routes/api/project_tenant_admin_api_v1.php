<?php

use App\Http\Middleware\CheckTenantAccess;
use Belluga\Events\Http\Api\v1\Controllers\EventStreamController;
use Belluga\Events\Http\Api\v1\Controllers\EventsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () {
        Route::get('/events', [EventsController::class, 'index'])
            ->middleware('abilities:events:read');
        Route::post('/events', [EventsController::class, 'store'])
            ->middleware('abilities:events:create');
        Route::patch('/events/{event_id}', [EventsController::class, 'update'])
            ->middleware('abilities:events:update');
        Route::delete('/events/{event_id}', [EventsController::class, 'destroy'])
            ->middleware('abilities:events:delete');
        Route::get('/events/stream', [EventStreamController::class, 'stream'])
            ->middleware('abilities:events:read');
        Route::get('/events/{event_id}', [EventsController::class, 'show'])
            ->middleware('abilities:events:read');
    });
