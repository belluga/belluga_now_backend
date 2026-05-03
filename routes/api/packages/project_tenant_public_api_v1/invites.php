<?php

declare(strict_types=1);

use App\Http\Api\v1\Controllers\ContactGroupController;
use App\Http\Api\v1\Controllers\ContactInviteablesController;
use App\Http\Middleware\CheckTenantAccess;
use Belluga\Invites\Http\Api\v1\Controllers\ContactImportController;
use Belluga\Invites\Http\Api\v1\Controllers\InviteActionController;
use Belluga\Invites\Http\Api\v1\Controllers\InviteFeedController;
use Belluga\Invites\Http\Api\v1\Controllers\InviteRealtimeStreamController;
use Belluga\Invites\Http\Api\v1\Controllers\InviteShareController;
use Illuminate\Support\Facades\Route;

Route::get('/invites/share/{code}', [InviteShareController::class, 'show']);

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function (): void {
        Route::get('/invites', [InviteFeedController::class, 'index']);
        Route::get('/invites/settings', [InviteFeedController::class, 'settings']);
        Route::get('/invites/stream', [InviteRealtimeStreamController::class, 'index']);
        Route::post('/invites', [InviteActionController::class, 'store']);
        Route::post('/invites/{invite_id}/accept', [InviteActionController::class, 'accept']);
        Route::post('/invites/{invite_id}/decline', [InviteActionController::class, 'decline']);
        Route::post('/invites/share', [InviteShareController::class, 'store']);
        Route::post('/invites/share/{code}/accept', [InviteShareController::class, 'accept']);
        Route::post('/invites/share/{code}/materialize', [InviteShareController::class, 'materialize']);
        Route::post('/contacts/import', [ContactImportController::class, 'store']);
        Route::get('/contacts/inviteables', [ContactInviteablesController::class, 'index']);
        Route::get('/contact-groups', [ContactGroupController::class, 'index']);
        Route::post('/contact-groups', [ContactGroupController::class, 'store']);
        Route::patch('/contact-groups/{group_id}', [ContactGroupController::class, 'update']);
        Route::delete('/contact-groups/{group_id}', [ContactGroupController::class, 'destroy']);
    });
