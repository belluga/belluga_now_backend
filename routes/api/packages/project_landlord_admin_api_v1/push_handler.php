<?php

declare(strict_types=1);

use Belluga\PushHandler\Http\Controllers\Landlord\TenantFirebaseSettingsAdminController;
use Belluga\PushHandler\Http\Controllers\Landlord\TenantPushSettingsAdminController;
use Illuminate\Support\Facades\Route;

$landlordTenantSettingsPath = '{tenant_slug}/settings/push';
$landlordTenantSettingsFirebasePath = '{tenant_slug}/settings/firebase';

Route::middleware(['auth:sanctum'])
    ->group(function () use ($landlordTenantSettingsPath, $landlordTenantSettingsFirebasePath): void {
        Route::get('/'.ltrim($landlordTenantSettingsPath, '/'), [TenantPushSettingsAdminController::class, 'show'])
            ->middleware('abilities:push-settings:update');
        Route::patch('/'.ltrim($landlordTenantSettingsPath, '/'), [TenantPushSettingsAdminController::class, 'update'])
            ->middleware('abilities:push-settings:update');
        Route::get('/'.ltrim($landlordTenantSettingsFirebasePath, '/'), [TenantFirebaseSettingsAdminController::class, 'show'])
            ->middleware('abilities:push-settings:update');
        Route::patch('/'.ltrim($landlordTenantSettingsFirebasePath, '/'), [TenantFirebaseSettingsAdminController::class, 'update'])
            ->middleware('abilities:push-settings:update');
    });
