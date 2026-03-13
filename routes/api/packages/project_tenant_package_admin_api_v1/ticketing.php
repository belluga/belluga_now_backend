<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketProductAdminController;
use Belluga\Ticketing\Http\Api\v1\Controllers\TicketPromotionAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function (): void {
        Route::get('/events/{event_id}/occurrences/{occurrence_id}/ticket_products', [TicketProductAdminController::class, 'index'])
            ->middleware('abilities:events:read');
        Route::post('/events/{event_id}/occurrences/{occurrence_id}/ticket_products', [TicketProductAdminController::class, 'store'])
            ->middleware('abilities:events:update');
        Route::get('/events/{event_id}/occurrences/{occurrence_id}/ticket_promotions', [TicketPromotionAdminController::class, 'index'])
            ->middleware('abilities:events:read');
        Route::post('/events/{event_id}/occurrences/{occurrence_id}/ticket_promotions', [TicketPromotionAdminController::class, 'store'])
            ->middleware('abilities:events:update');
    });
