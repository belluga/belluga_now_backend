<?php

use App\Http\Api\v1\Controllers\AccountProfilesController;
use App\Http\Api\v1\Controllers\EventAttendanceController;
use App\Http\Api\v1\Controllers\StaticAssetsController;
use App\Http\Middleware\CheckTenantAccess;
use Belluga\Events\Http\Api\v1\Controllers\AgendaController;
use Belluga\Events\Http\Api\v1\Controllers\EventsController;
use Belluga\Events\Http\Api\v1\Controllers\EventStreamController;
use Illuminate\Support\Facades\Route;

require base_path('routes/api/packages/project_tenant_public_api_v1/invites.php');
require base_path('routes/api/packages/project_tenant_public_api_v1/favorites.php');
require base_path('routes/api/packages/project_tenant_public_api_v1/map_pois.php');
require base_path('routes/api/packages/project_tenant_public_api_v1/push_handler.php');
require base_path('routes/api/packages/project_tenant_public_api_v1/ticketing.php');

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () {
        Route::get('/agenda', [AgendaController::class, 'index']);
        Route::get('/events', [EventsController::class, 'index']);
        Route::get('/events/stream', [EventStreamController::class, 'stream']);
        Route::get('/events/attendance/confirmed', [EventAttendanceController::class, 'index']);
        Route::post('/events/{event_id}/attendance/confirm', [EventAttendanceController::class, 'confirm']);
        Route::post('/events/{event_id}/attendance/unconfirm', [EventAttendanceController::class, 'unconfirm']);
        Route::get('/events/{event_id}', [EventsController::class, 'show']);
        Route::get('/account_profiles', [AccountProfilesController::class, 'publicIndex']);
        Route::get('/static_assets/{asset_ref}', [StaticAssetsController::class, 'showPublic']);
    });
