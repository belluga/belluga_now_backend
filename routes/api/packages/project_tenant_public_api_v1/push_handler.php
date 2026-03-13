<?php

declare(strict_types=1);

use App\Http\Middleware\CheckTenantAccess;
use Belluga\PushHandler\Http\Controllers\Tenant\PushCredentialController;
use Belluga\PushHandler\Http\Controllers\Tenant\PushDeviceController;
use Belluga\PushHandler\Http\Controllers\Tenant\PushMessageActionController as TenantPushMessageActionController;
use Belluga\PushHandler\Http\Controllers\Tenant\PushMessageController as TenantPushMessageController;
use Belluga\PushHandler\Http\Controllers\Tenant\PushMessageDataController as TenantPushMessageDataController;
use Belluga\PushHandler\Http\Controllers\Tenant\PushMessageSendController as TenantPushMessageSendController;
use Belluga\PushHandler\Http\Controllers\Tenant\TenantFirebaseSettingsController;
use Belluga\PushHandler\Http\Controllers\Tenant\TenantPushDisableController;
use Belluga\PushHandler\Http\Controllers\Tenant\TenantPushEnableController;
use Belluga\PushHandler\Http\Controllers\Tenant\TenantPushMessageTypesController;
use Belluga\PushHandler\Http\Controllers\Tenant\TenantPushRouteTypesController;
use Belluga\PushHandler\Http\Controllers\Tenant\TenantPushSettingsController;
use Belluga\PushHandler\Http\Controllers\Tenant\TenantPushStatusController;
use Illuminate\Support\Facades\Route;

$tenantRegisterPath = 'push/register';
$tenantUnregisterPath = 'push/unregister';
$tenantSettingsPrefix = 'settings';
$tenantSettingsPushPath = 'push';
$tenantSettingsFirebasePath = 'firebase';

Route::post('/'.ltrim($tenantRegisterPath, '/'), [PushDeviceController::class, 'register'])
    ->middleware(['auth:sanctum', CheckTenantAccess::class]);
Route::delete('/'.ltrim($tenantUnregisterPath, '/'), [PushDeviceController::class, 'unregister'])
    ->middleware(['auth:sanctum', CheckTenantAccess::class]);

Route::prefix('push/messages')
    ->middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function (): void {
        Route::get('/', [TenantPushMessageController::class, 'index'])
            ->middleware('abilities:tenant-push-messages:read');
        Route::post('/', [TenantPushMessageController::class, 'store'])
            ->middleware('abilities:tenant-push-messages:create');
        Route::get('/{push_message_id}', [TenantPushMessageController::class, 'show'])
            ->middleware('abilities:tenant-push-messages:read');
        Route::patch('/{push_message_id}', [TenantPushMessageController::class, 'update'])
            ->middleware('abilities:tenant-push-messages:update');
        Route::delete('/{push_message_id}', [TenantPushMessageController::class, 'destroy'])
            ->middleware('abilities:tenant-push-messages:delete');

        Route::get('/{push_message_id}/data', [TenantPushMessageDataController::class, 'show']);
        Route::post('/{push_message_id}/actions', [TenantPushMessageActionController::class, 'store']);
        Route::post('/{push_message_id}/send', TenantPushMessageSendController::class)
            ->middleware('abilities:tenant-push-messages:send');
    });

Route::prefix($tenantSettingsPrefix)
    ->middleware(['auth:sanctum', CheckTenantAccess::class])
    ->group(function () use ($tenantSettingsPushPath, $tenantSettingsFirebasePath): void {
        Route::get('/'.ltrim($tenantSettingsPushPath, '/'), [TenantPushSettingsController::class, 'show'])
            ->middleware('abilities:push-settings:update');
        Route::patch('/'.ltrim($tenantSettingsPushPath, '/'), [TenantPushSettingsController::class, 'update'])
            ->middleware('abilities:push-settings:update');
        Route::get('/'.ltrim($tenantSettingsFirebasePath, '/'), [TenantFirebaseSettingsController::class, 'show'])
            ->middleware('abilities:push-settings:update');
        Route::patch('/'.ltrim($tenantSettingsFirebasePath, '/'), [TenantFirebaseSettingsController::class, 'update'])
            ->middleware('abilities:push-settings:update');
        Route::post('/'.ltrim($tenantSettingsPushPath, '/').'/enable', TenantPushEnableController::class)
            ->middleware('abilities:push-settings:update');
        Route::post('/'.ltrim($tenantSettingsPushPath, '/').'/disable', TenantPushDisableController::class)
            ->middleware('abilities:push-settings:update');
        Route::get('/'.ltrim($tenantSettingsPushPath, '/').'/route_types', [TenantPushRouteTypesController::class, 'show'])
            ->middleware('abilities:push-settings:update');
        Route::patch('/'.ltrim($tenantSettingsPushPath, '/').'/route_types', [TenantPushRouteTypesController::class, 'update'])
            ->middleware('abilities:push-settings:update');
        Route::delete('/'.ltrim($tenantSettingsPushPath, '/').'/route_types', [TenantPushRouteTypesController::class, 'destroy'])
            ->middleware('abilities:push-settings:update');
        Route::get('/'.ltrim($tenantSettingsPushPath, '/').'/message_types', [TenantPushMessageTypesController::class, 'show'])
            ->middleware('abilities:push-settings:update');
        Route::patch('/'.ltrim($tenantSettingsPushPath, '/').'/message_types', [TenantPushMessageTypesController::class, 'update'])
            ->middleware('abilities:push-settings:update');
        Route::delete('/'.ltrim($tenantSettingsPushPath, '/').'/message_types', [TenantPushMessageTypesController::class, 'destroy'])
            ->middleware('abilities:push-settings:update');
        Route::get('/'.ltrim($tenantSettingsPushPath, '/').'/status', [TenantPushStatusController::class, 'show'])
            ->middleware('abilities:push-settings:update');

        Route::prefix('push/credentials')->group(function (): void {
            Route::get('/', [PushCredentialController::class, 'index'])
                ->middleware('abilities:tenant-push-credentials:read');
            Route::put('/', [PushCredentialController::class, 'upsert'])
                ->middleware('abilities:tenant-push-credentials:update');
        });
    });
