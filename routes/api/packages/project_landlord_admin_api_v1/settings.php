<?php

declare(strict_types=1);

use Belluga\Settings\Http\Api\v1\Controllers\Landlord\LandlordSettingsKernelController;
use Belluga\Settings\Http\Api\v1\Controllers\Landlord\TenantSettingsKernelController;
use Illuminate\Support\Facades\Route;

$landlordSettingsPrefix = 'settings';
$landlordTenantSettingsPrefix = '{tenant_slug}/settings';

Route::middleware(['auth:sanctum'])
    ->group(function () use ($landlordSettingsPrefix, $landlordTenantSettingsPrefix): void {
        Route::prefix($landlordSettingsPrefix)
            ->group(function (): void {
                Route::get('/schema', [LandlordSettingsKernelController::class, 'schema']);
                Route::get('/values', [LandlordSettingsKernelController::class, 'values']);
                Route::patch('/values/{namespace}', [LandlordSettingsKernelController::class, 'patch']);
            });

        Route::prefix($landlordTenantSettingsPrefix)
            ->group(function (): void {
                Route::get('/schema', [TenantSettingsKernelController::class, 'schema']);
                Route::get('/values', [TenantSettingsKernelController::class, 'values']);
                Route::patch('/values/{namespace}', [TenantSettingsKernelController::class, 'patch']);
            });
    });
