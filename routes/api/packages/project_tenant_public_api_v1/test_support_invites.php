<?php

declare(strict_types=1);

use App\Http\Api\v1\Controllers\TestSupport\InviteStageTestSupportController;
use Illuminate\Support\Facades\Route;

Route::middleware('invite-stage-test-support')
    ->prefix('test-support/invites')
    ->group(function (): void {
        Route::post('/bootstrap', [InviteStageTestSupportController::class, 'bootstrap']);
        Route::get('/state/{run_id}', [InviteStageTestSupportController::class, 'state']);
        Route::post('/cleanup', [InviteStageTestSupportController::class, 'cleanup']);
    });
