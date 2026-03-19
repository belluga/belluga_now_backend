<?php

declare(strict_types=1);

use App\Http\Api\v1\Controllers\Security\ApiAbuseSignalsController;
use Illuminate\Support\Facades\Route;

require base_path('routes/api/packages/project_landlord_admin_api_v1/settings.php');
require base_path('routes/api/packages/project_landlord_admin_api_v1/push_handler.php');

Route::prefix('security')
    ->middleware(['auth:sanctum'])
    ->group(function (): void {
        Route::get('/abuse-signals', [ApiAbuseSignalsController::class, 'index'])
            ->middleware('abilities:security-signals:read');

        Route::get('/abuse-signals/summary', [ApiAbuseSignalsController::class, 'summary'])
            ->middleware('abilities:security-signals:read');
    });
