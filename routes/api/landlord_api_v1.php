<?php

use App\Http\Api\v1\Controllers\InitializationController;
use App\Http\Api\v1\Controllers\TenantController;
use App\Http\Api\v1\Controllers\LandlordUserController;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AuthControllerLandlord;

Route::prefix('initialize')->middleware('guest')->group(function () {
    Route::post('/', [InitializationController::class, 'initialize'])
        ->name('admin.initialize');
});

// Rotas públicas
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthControllerLandlord::class, 'login'])
        ->name('admin.auth.login');
//
    Route::post('/check', [AuthControllerLandlord::class, 'loginByToken'])
        ->middleware(['auth:sanctum'])
        ->name('admin.auth.check');
//
    Route::post('/logout', [AuthControllerLandlord::class, 'logout'])
        ->middleware(['auth:sanctum'])
        ->name('admin.auth.logout');
});

Route::prefix('tenants')->group(function () {
    Route::get('/', [TenantController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:tenants:read')
        ->name('tenants.index');

    Route::post('/', [TenantController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:tenants:write')
        ->name('tenants.store');

    Route::get('/{tenant_slug}', [TenantController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:tenants:read')
        ->name('tenants.show');

    Route::patch('/{tenant_slug}', [TenantController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:tenants:write')
        ->name('tenants.update');

    Route::delete('/{tenant_slug}', [TenantController::class, 'destroy'])
        ->middleware('auth:sanctum', 'abilities:tenants:delete')
        ->name('tenants.destroy');

    Route::post('/{tenant_slug}/restore', [TenantController::class, 'restore'])
        ->middleware('auth:sanctum', 'abilities:tenants:manage')
        ->name('tenants.restore');

    Route::delete('/{tenant_slug}/force_delete', [TenantController::class, 'forceDestroy'])
        ->middleware('auth:sanctum', 'abilities:tenants:delete')
        ->name('tenants.destroy');
});

Route::prefix('users')->group(function () {
    Route::get('/', [LandlordUserController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:read')
        ->name('users.index');

    Route::post('/', [LandlordUserController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.store');

    Route::get('/{user_id}', [LandlordUserController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:read')
        ->name('users.show');

    Route::patch('/{user_id}', [LandlordUserController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.update');

    Route::delete('/{user_id}/force_delete', [LandlordUserController::class, 'forceDestroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.destroy');

    Route::post('/{user_id}/restore', [LandlordUserController::class, 'restore'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.restore');

    Route::delete('/{user_id}', [LandlordUserController::class, 'destroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.force_destroy');

    Route::post('/{user_id}/tenants', [LandlordUserController::class, 'tenantUserManage'])
        ->middleware('auth:sanctum', 'abilities:tenants:manage')
        ->name('manage.tenants.users.attach');

    Route::delete('/{user_id}/tenants', [LandlordUserController::class, 'tenantUserManage'])
        ->middleware('auth:sanctum', 'abilities:tenants:manage')
        ->name('manage.tenants.users.detach');
//
//    // Alterar senha
//    Route::put('/{id}/password', [UserController::class, 'updatePassword'])
//        ->name('users.password.update');
});
