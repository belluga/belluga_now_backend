<?php

use Belluga\Events\Http\Api\v1\Controllers\EventsController;
use Illuminate\Support\Facades\Route;

require base_path('routes/api/packages/project_account_api_v1/push_handler.php');

Route::middleware(['auth:sanctum', 'account'])
    ->group(function () {
        Route::get('/events/party_candidates', [EventsController::class, 'partyCandidates'])
            ->middleware('ability:events:read,events:create,events:update');
        Route::get('/events', [EventsController::class, 'index'])
            ->middleware('abilities:events:read');
        Route::post('/events', [EventsController::class, 'store'])
            ->middleware('abilities:events:create');
        Route::patch('/events/{event_id}', [EventsController::class, 'update'])
            ->middleware('abilities:events:update');
        Route::delete('/events/{event_id}', [EventsController::class, 'destroy'])
            ->middleware('abilities:events:delete');
        Route::get('/events/{event_id}', [EventsController::class, 'show'])
            ->middleware('abilities:events:read');
    });
