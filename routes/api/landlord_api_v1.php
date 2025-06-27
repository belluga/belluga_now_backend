<?php

use App\Http\Api\v1\Controllers\TenantController;
use App\Http\Api\v1\Controllers\LandlordUserController;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AuthControllerLandlord;
use App\Http\Api\v1\Controllers\LandlordRolesController;
use App\Http\Api\v1\Controllers\TenantRolesController;

Route::prefix('auth')->group(function () {
    Route::withoutMiddleware('landlord')
        ->group(function () {

            Route::post('/login', [AuthControllerLandlord::class, 'login'])
                ->name('admin.auth.login');

            Route::post('/check', [AuthControllerLandlord::class, 'loginByToken'])
                ->middleware(['auth:sanctum'])
                ->name('admin.auth.check');
        });

    Route::post('/logout', [AuthControllerLandlord::class, 'logout'])
        ->middleware(['auth:sanctum'])
        ->name('admin.auth.logout');
});

Route::prefix('tenants')->group(function () {
    Route::get('/', [TenantController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:tenants:read')
        ->name('tenants.index');

    Route::post('/', [TenantController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:tenants:create')
        ->name('tenants.store');

    Route::get('/{tenant_slug}', [TenantController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:tenants:read')
        ->name('tenants.show');

    Route::patch('/{tenant_slug}', [TenantController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:tenants:create,tenants:update')
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

    // Tenant Roles
    Route::get('{tenant_slug}/roles', [LandlordRolesController::class, 'tenantRoles'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:update,tenants:manage');
    Route::post('{tenant_slug}/roles', [LandlordRolesController::class, 'storeTenantRole'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:update,tenants:manage');;
});

Route::prefix('users')->group(function () {
    Route::get('/', [LandlordUserController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:read')
        ->name('users.index');

    Route::post('/', [LandlordUserController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:create')
        ->name('users.store');

    Route::get('/{user_id}', [LandlordUserController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:read')
        ->name('users.show');

    Route::patch('/{user_id}', [LandlordUserController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:update,landlord-users:create')
        ->name('users.update');

    Route::delete('/{user_id}/force_delete', [LandlordUserController::class, 'forceDestroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:delete')
        ->name('users.destroy');

    Route::post('/{user_id}/restore', [LandlordUserController::class, 'restore'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:update,landlord-users:delete')
        ->name('users.restore');

    Route::delete('/{user_id}', [LandlordUserController::class, 'destroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:delete')
        ->name('users.force_destroy');
//
//    // Alterar senha
//    Route::put('/{id}/password', [UserController::class, 'updatePassword'])
//        ->name('users.password.update');
});

Route::prefix('roles')->group(function () {
    Route::get('/', [LandlordRolesController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:landlord-roles:view');

    Route::post('/', [LandlordRolesController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:landlord-roles:create');

    Route::get('{role_id}', [LandlordRolesController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:landlord-roles:view');

    Route::patch('{role_id}', [LandlordRolesController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:landlord-roles:update');

    Route::delete('{role_id}', [LandlordRolesController::class, 'destroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-roles:delete');

    Route::delete('{role_id}/force_delete', [LandlordRolesController::class, 'forceDestroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-roles:delete');

    Route::post('{role_id}/restore', [LandlordRolesController::class, 'restore'])
        ->middleware('auth:sanctum', 'abilities:landlord-roles:update,landlord-roles:delete');
});

Route::prefix('tenant/{tenant_slug}/roles')->group(function () {
    Route::get('/', [TenantRolesController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:tenant-roles:view');

    Route::post('/', [TenantRolesController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:tenant-roles:create');

    Route::get('{role_id}', [TenantRolesController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:tenant-roles:view');

    Route::patch('{role_id}', [TenantRolesController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:tenant-roles:update');

    Route::delete('{role_id}', [TenantRolesController::class, 'destroy'])
        ->middleware('auth:sanctum', 'abilities:tenant-roles:delete');

    Route::delete('{role_id}/force_delete', [TenantRolesController::class, 'forceDestroy'])
        ->middleware('auth:sanctum', 'abilities:tenant-roles:delete');

    Route::post('{role_id}/restore', [TenantRolesController::class, 'restore'])
        ->middleware('auth:sanctum', 'abilities:tenant-roles:update,tenant-roles:delete');
});
