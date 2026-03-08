<?php

use App\Http\Middleware\CheckTenantAccess;
use App\Http\Api\v1\Controllers\AccountOnboardingsController;
use App\Http\Api\v1\Controllers\TenantAdminLegacyCreateGuardController;
use Belluga\Events\Http\Api\v1\Controllers\EventStreamController;
use Belluga\Events\Http\Api\v1\Controllers\EventsController;
use App\Http\Api\v1\Controllers\EventTypesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () {
        Route::post('/accounts', [TenantAdminLegacyCreateGuardController::class, 'rejectAccountsCreate'])
            ->middleware('abilities:account-users:create');
        Route::post('/account_profiles', [TenantAdminLegacyCreateGuardController::class, 'rejectAccountProfilesCreate'])
            ->middleware('abilities:account-users:create');
        Route::post('/account_onboardings', [AccountOnboardingsController::class, 'store'])
            ->middleware('abilities:account-users:create');

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
        Route::get('/events/stream', [EventStreamController::class, 'stream'])
            ->middleware('abilities:events:read');
        Route::get('/events/{event_id}', [EventsController::class, 'show'])
            ->middleware('abilities:events:read');

        Route::get('/event_types', [EventTypesController::class, 'index'])
            ->middleware('ability:events:read,events:create,events:update');
        Route::post('/event_types', [EventTypesController::class, 'store'])
            ->middleware('abilities:events:create');
        Route::patch('/event_types/{event_type}', [EventTypesController::class, 'update'])
            ->middleware('abilities:events:update');
        Route::delete('/event_types/{event_type}', [EventTypesController::class, 'destroy'])
            ->middleware('abilities:events:delete');
    });
