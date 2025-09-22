<?php

use App\Http\Api\v1\Controllers\TenantController;
use App\Http\Api\v1\Controllers\LandlordBrandingController;
use App\Http\Api\v1\Controllers\BrandingController;
use App\Http\Api\v1\Controllers\LandlordUserController;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AuthControllerLandlord;
use App\Http\Api\v1\Controllers\LandlordRolesController;
use App\Http\Api\v1\Controllers\ProfileControllerLandlord;

Route::prefix('profile')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::patch('/password', [ProfileControllerLandlord::class, 'updatePassword']);

        Route::patch('/', [ProfileControllerLandlord::class, 'updateProfile']);

        Route::patch('/emails', [ProfileControllerLandlord::class, 'addEmails']);

        Route::delete('/emails', [ProfileControllerLandlord::class, 'removeEmail']);

        Route::patch('/phones', [ProfileControllerLandlord::class, 'addPhones']);

        Route::delete('/phones', [ProfileControllerLandlord::class, 'removePhone']);
});

Route::prefix('auth')->group(function () {

    Route::post('/logout', [AuthControllerLandlord::class, 'logout'])
        ->middleware(['auth:sanctum']);

    Route::withoutMiddleware('landlord')
        ->group(function () {
            Route::post('/login', [AuthControllerLandlord::class, 'login']);

            Route::post('/password_token', [ProfileControllerLandlord::class, 'generateToken']);

            Route::post('/password_reset', [ProfileControllerLandlord::class, 'resetPassword']);

            Route::get('/token_validate', [AuthControllerLandlord::class, 'loginByToken'])
                ->middleware(['auth:sanctum']);
        });
});

Route::prefix('tenants')->group(function () {
    Route::get('/', [TenantController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:tenants:read');

    Route::post('/', [TenantController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:tenants:create');

    Route::get('/{tenant_slug}', [TenantController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:tenants:read');

    Route::patch('/{tenant_slug}', [TenantController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:tenants:create,tenants:update');

    Route::delete('/{tenant_slug}', [TenantController::class, 'destroy'])
        ->middleware('auth:sanctum', 'abilities:tenants:delete');

    Route::post('/{tenant_slug}/restore', [TenantController::class, 'restore'])
        ->middleware('auth:sanctum', 'abilities:tenants:manage');

    Route::delete('/{tenant_slug}/force_delete', [TenantController::class, 'forceDestroy'])
        ->middleware('auth:sanctum', 'abilities:tenants:delete');
});

Route::prefix('users')->group(function () {
    Route::get('/', [LandlordUserController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:read');

    Route::post('/', [LandlordUserController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:create');

    Route::get('/{user_id}', [LandlordUserController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:read');

    Route::patch('/{user_id}', [LandlordUserController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:update,landlord-users:create');

    Route::delete('/{user_id}/force_delete', [LandlordUserController::class, 'forceDestroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:delete');

    Route::post('/{user_id}/restore', [LandlordUserController::class, 'restore'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:update,landlord-users:delete');

    Route::delete('/{user_id}', [LandlordUserController::class, 'destroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:delete');
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

Route::prefix('branding')->group(function () {
    Route::post('/update', [LandlordBrandingController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:tenant-branding:update');

});
