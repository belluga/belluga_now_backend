<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketAdmissionController;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketCartController;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketCheckoutController;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketOfferController;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketProductAdminController;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketRealtimeStreamController;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketTokenController;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketValidationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')
    ->middleware(['tenant'])
    ->group(function (): void {
        Route::get('/events/{event_ref}/occurrences/{occurrence_ref}/offer', [TicketOfferController::class, 'occurrence']);
        Route::get('/occurrences/{occurrence_ref}/offer', [TicketOfferController::class, 'occurrenceOnly']);
        Route::get('/ticketing/streams/offer/{scope_type}/{scope_id}', [TicketRealtimeStreamController::class, 'offer']);
    });

Route::prefix('api/v1')
    ->middleware(['tenant', 'auth:sanctum', CheckTenantAccess::class])
    ->group(function (): void {
        Route::post('/events/{event_ref}/occurrences/{occurrence_ref}/admission', [TicketAdmissionController::class, 'occurrence']);
        Route::post('/occurrences/{occurrence_ref}/admission', [TicketAdmissionController::class, 'occurrenceOnly']);
        Route::post('/admission/tokens/refresh', [TicketTokenController::class, 'refresh']);
        Route::get('/checkout/cart', [TicketCartController::class, 'show']);
        Route::post('/checkout/confirm', [TicketCheckoutController::class, 'confirm']);
        Route::post('/events/{event_id}/occurrences/{occurrence_id}/validation', [TicketValidationController::class, 'validateOccurrence']);
        Route::get('/ticketing/streams/queue/{scope_type}/{scope_id}', [TicketRealtimeStreamController::class, 'queue']);
        Route::get('/ticketing/streams/hold/{hold_id}', [TicketRealtimeStreamController::class, 'hold']);
    });

Route::prefix('admin/api/v1')
    ->middleware(['tenant', 'landlord', 'auth:sanctum', CheckTenantAccess::class])
    ->group(function (): void {
        Route::get('/events/{event_id}/occurrences/{occurrence_id}/ticket_products', [TicketProductAdminController::class, 'index'])
            ->middleware('abilities:events:read');
        Route::post('/events/{event_id}/occurrences/{occurrence_id}/ticket_products', [TicketProductAdminController::class, 'store'])
            ->middleware('abilities:events:update');
    });
