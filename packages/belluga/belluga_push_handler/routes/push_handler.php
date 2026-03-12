<?php

use App\Http\Middleware\CheckTenantAccess;
use App\Http\Middleware\InitializeAccount;
use Belluga\PushHandler\Http\Controllers\Account\PushMessageActionController;
use Belluga\PushHandler\Http\Controllers\Account\PushMessageController;
use Belluga\PushHandler\Http\Controllers\Account\PushMessageDataController;
use Belluga\PushHandler\Http\Controllers\Account\PushMessageSendController;
use Belluga\PushHandler\Http\Controllers\Account\PushQuotaCheckController;
use Belluga\PushHandler\Http\Controllers\Landlord\TenantFirebaseSettingsAdminController;
use Belluga\PushHandler\Http\Controllers\Landlord\TenantPushSettingsAdminController;
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

$routes = config('belluga_push_handler.routes', []);
$accountRoutes = $routes['account'] ?? [];
$tenantRoutes = $routes['tenant'] ?? [];
$landlordRoutes = $routes['landlord'] ?? [];

$accountPrefix = $accountRoutes['prefix'] ?? 'api/v1/accounts/{account_slug}';
$accountMessagesPrefix = $accountRoutes['messages_prefix'] ?? 'push/messages';

$tenantPrefix = $tenantRoutes['prefix'] ?? 'api/v1';
$tenantRegisterPath = $tenantRoutes['register'] ?? 'push/register';
$tenantUnregisterPath = $tenantRoutes['unregister'] ?? 'push/unregister';
$tenantSettingsPrefix = $tenantRoutes['settings_prefix'] ?? 'settings';
$tenantSettingsPushPath = $tenantRoutes['settings_push'] ?? 'push';
$tenantSettingsFirebasePath = $tenantRoutes['settings_firebase'] ?? 'firebase';

$landlordPrefix = $landlordRoutes['prefix'] ?? 'admin/api/v1';
$landlordTenantSettingsPath = $landlordRoutes['tenant_settings_path'] ?? '{tenant_slug}/settings/push';
$landlordTenantSettingsFirebasePath = $landlordRoutes['tenant_settings_firebase_path'] ?? '{tenant_slug}/settings/firebase';

Route::prefix($accountPrefix)
    ->middleware(['tenant'])
    ->group(function () use ($accountMessagesPrefix) {
        Route::middleware('auth:sanctum')
            ->group(function () use ($accountMessagesPrefix) {
                Route::get('/push/quota-check', PushQuotaCheckController::class)
                    ->middleware('account', 'abilities:push-messages:send');

                Route::prefix($accountMessagesPrefix)
                    ->group(function () {
                        Route::get('/', [PushMessageController::class, 'index'])
                            ->middleware('account', 'abilities:push-messages:read');
                        Route::post('/', [PushMessageController::class, 'store'])
                            ->middleware('account', 'abilities:push-messages:create');
                        Route::get('/{push_message_id}', [PushMessageController::class, 'show'])
                            ->middleware('account', 'abilities:push-messages:read');
                        Route::patch('/{push_message_id}', [PushMessageController::class, 'update'])
                            ->middleware('account', 'abilities:push-messages:update');
                        Route::delete('/{push_message_id}', [PushMessageController::class, 'destroy'])
                            ->middleware('account', 'abilities:push-messages:delete');

                        Route::get('/{push_message_id}/data', [PushMessageDataController::class, 'show'])
                            ->middleware(InitializeAccount::class);
                        Route::post('/{push_message_id}/actions', [PushMessageActionController::class, 'store'])
                            ->middleware(InitializeAccount::class);
                        Route::post('/{push_message_id}/send', PushMessageSendController::class)
                            ->middleware('account', 'abilities:push-messages:send');
                    });
            });
    });

Route::prefix($tenantPrefix)
    ->middleware(['tenant'])
    ->group(function () use (
        $tenantRegisterPath,
        $tenantUnregisterPath,
        $tenantSettingsPrefix,
        $tenantSettingsPushPath,
        $tenantSettingsFirebasePath
    ) {
        Route::post('/'.ltrim($tenantRegisterPath, '/'), [PushDeviceController::class, 'register'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class]);
        Route::delete('/'.ltrim($tenantUnregisterPath, '/'), [PushDeviceController::class, 'unregister'])
            ->middleware(['auth:sanctum', CheckTenantAccess::class]);

        Route::prefix('push/messages')
            ->middleware(['auth:sanctum', CheckTenantAccess::class])
            ->group(function () {
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
            ->group(function () use ($tenantSettingsPushPath, $tenantSettingsFirebasePath) {
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

                Route::prefix('push/credentials')->group(function () {
                    Route::get('/', [PushCredentialController::class, 'index'])
                        ->middleware('abilities:tenant-push-credentials:read');
                    Route::put('/', [PushCredentialController::class, 'upsert'])
                        ->middleware('abilities:tenant-push-credentials:update');
                });
            });
    });

Route::prefix($landlordPrefix)
    ->middleware(['landlord'])
    ->group(function () use (
        $landlordTenantSettingsPath,
        $landlordTenantSettingsFirebasePath
    ) {
        Route::get('/'.ltrim($landlordTenantSettingsPath, '/'), [TenantPushSettingsAdminController::class, 'show'])
            ->middleware('auth:sanctum', 'abilities:push-settings:update');
        Route::patch('/'.ltrim($landlordTenantSettingsPath, '/'), [TenantPushSettingsAdminController::class, 'update'])
            ->middleware('auth:sanctum', 'abilities:push-settings:update');
        Route::get('/'.ltrim($landlordTenantSettingsFirebasePath, '/'), [TenantFirebaseSettingsAdminController::class, 'show'])
            ->middleware('auth:sanctum', 'abilities:push-settings:update');
        Route::patch('/'.ltrim($landlordTenantSettingsFirebasePath, '/'), [TenantFirebaseSettingsAdminController::class, 'update'])
            ->middleware('auth:sanctum', 'abilities:push-settings:update');
    });
