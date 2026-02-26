<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use Belluga\Settings\Http\Api\v1\Controllers\Landlord\LandlordSettingsKernelController;
use Belluga\Settings\Http\Api\v1\Controllers\Landlord\TenantSettingsKernelController;
use Belluga\Settings\Http\Api\v1\Controllers\Tenant\SettingsKernelController;
use Illuminate\Support\Facades\Route;

$routes = (array) config('belluga_settings.routes', []);
$tenantRoutes = (array) ($routes['tenant'] ?? []);
$landlordRoutes = (array) ($routes['landlord'] ?? []);

$tenantPrefix = (string) ($tenantRoutes['prefix'] ?? 'api/v1');
$tenantSettingsPrefix = (string) ($tenantRoutes['settings_prefix'] ?? 'settings');

$landlordPrefix = (string) ($landlordRoutes['prefix'] ?? 'admin/api/v1');
$landlordSettingsPrefix = (string) ($landlordRoutes['settings_prefix'] ?? 'settings');
$landlordTenantSettingsPrefix = (string) ($landlordRoutes['tenant_settings_prefix'] ?? '{tenant_slug}/settings');

Route::prefix($tenantPrefix)
    ->middleware(['tenant'])
    ->group(function () use ($tenantSettingsPrefix): void {
        Route::prefix($tenantSettingsPrefix)
            ->middleware(['auth:sanctum', CheckTenantAccess::class])
            ->group(function (): void {
                Route::get('/schema', [SettingsKernelController::class, 'schema']);
                Route::get('/values', [SettingsKernelController::class, 'values']);
                Route::patch('/values/{namespace}', [SettingsKernelController::class, 'patch']);
            });
    });

Route::prefix($landlordPrefix)
    ->middleware(['landlord'])
    ->group(function () use ($landlordSettingsPrefix, $landlordTenantSettingsPrefix): void {
        Route::prefix($landlordSettingsPrefix)
            ->middleware(['auth:sanctum'])
            ->group(function (): void {
                Route::get('/schema', [LandlordSettingsKernelController::class, 'schema']);
                Route::get('/values', [LandlordSettingsKernelController::class, 'values']);
                Route::patch('/values/{namespace}', [LandlordSettingsKernelController::class, 'patch']);
            });

        Route::prefix($landlordTenantSettingsPrefix)
            ->middleware(['auth:sanctum'])
            ->group(function (): void {
                Route::get('/schema', [TenantSettingsKernelController::class, 'schema']);
                Route::get('/values', [TenantSettingsKernelController::class, 'values']);
                Route::patch('/values/{namespace}', [TenantSettingsKernelController::class, 'patch']);
            });
    });
