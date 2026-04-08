<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use App\Http\Api\v1\Controllers\TenantTelemetrySettingsController;
use Belluga\Settings\Http\Api\v1\Controllers\Tenant\SettingsKernelController;
use Illuminate\Support\Facades\Route;

$tenantSettingsPrefix = 'settings';

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () use ($tenantSettingsPrefix): void {
        Route::prefix($tenantSettingsPrefix)
            ->group(function (): void {
                Route::get('/schema', [SettingsKernelController::class, 'schema']);
                Route::get('/values', [SettingsKernelController::class, 'values']);
                Route::patch('/values/{namespace}', [SettingsKernelController::class, 'patch']);
                Route::get('/telemetry', [TenantTelemetrySettingsController::class, 'index'])
                    ->middleware('abilities:telemetry-settings:update');
                Route::post('/telemetry', [TenantTelemetrySettingsController::class, 'store'])
                    ->middleware('abilities:telemetry-settings:update');
                Route::delete('/telemetry/{type}', [TenantTelemetrySettingsController::class, 'destroy'])
                    ->middleware('abilities:telemetry-settings:update');
            });
    });
