<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use Belluga\Settings\Http\Api\v1\Controllers\Tenant\SettingsKernelController;
use Illuminate\Support\Facades\Route;

$routes = (array) config('belluga_settings.routes', []);
$tenantRoutes = (array) ($routes['tenant'] ?? []);
$tenantSettingsPrefix = (string) ($tenantRoutes['settings_prefix'] ?? 'settings');

Route::middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () use ($tenantSettingsPrefix): void {
        Route::prefix($tenantSettingsPrefix)
            ->group(function (): void {
                Route::get('/schema', [SettingsKernelController::class, 'schema']);
                Route::get('/values', [SettingsKernelController::class, 'values']);
                Route::patch('/values/{namespace}', [SettingsKernelController::class, 'patch']);
            });
    });
