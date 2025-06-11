<?php

use App\Http\Api\v1\Controllers\AuthControllerTenant;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\RolesAccountController;
use App\Http\Api\v1\Controllers\TenantUserController;

Route::prefix('auth')
    ->group(function () {
        Route::post('/login', [AuthControllerTenant::class, 'login'])
            ->name('tenant.auth.login');

        Route::post('/logout', [AuthControllerTenant::class, 'logout'])
            ->name('tenant.auth.login');
    });

Route::prefix('users')
    ->group(function () {

        Route::get('/', [TenantUserController::class, 'index'])
            ->middleware('auth:sanctum', "abilities:account-users:view")
            ->name('tenant.users.index');

        Route::post('/', [TenantUserController::class, 'store'])
            ->middleware('auth:sanctum', "abilities:account-users:create")
            ->name('tenant.users.store');

        Route::get('/{user_id}', [TenantUserController::class, 'show'])
            ->middleware('auth:sanctum', "abilities:account-users:view")
            ->name('tenant.users.show');

        Route::patch('/{user_id}', [TenantUserController::class, 'update'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.update');

        Route::delete('/{user_id}', [TenantUserController::class, 'destroy'])
            ->middleware('auth:sanctum', "abilities:account-users:delete")
            ->name('tenant.users.destroy');

        Route::delete('/{user_id}/force_delete', [TenantUserController::class, 'forceDestroy'])
            ->middleware('auth:sanctum', "abilities:account-users:delete")
            ->name('tenant.users.force_destroy');

        Route::post('/{user_id}/restore', [TenantUserController::class, 'restore'])
            ->middleware('auth:sanctum', "abilities:account-users:create,account-users:update")
            ->name('tenant.users.restore');

        Route::patch('/{user_id}/emails', [TenantUserController::class, 'addEmails'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.add_emails');

        Route::delete('/{user_id}/emails', [TenantUserController::class, 'removeEmails'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.remove_emails');

        Route::put('/{id}/password', [TenantUserController::class, 'updatePassword'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.password.update');

        Route::patch('/{id}/toggle-active', [TenantUserController::class, 'toggleActive'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.toggle-active');
    });

Route::prefix("roles")
    ->group(function () {

        Route::get('/', [RolesAccountController::class, 'index'])
            ->middleware('auth:sanctum', "abilities:account-roles:view")
            ->name('tenant.accounts.roles.list');

        Route::post('/', [RolesAccountController::class, 'store'])
            ->middleware('auth:sanctum', "abilities:account-roles:create")
            ->name('tenant.accounts.roles.create');

        Route::get('/{role_id}', [RolesAccountController::class, 'show'])
            ->middleware('auth:sanctum', "abilities:account-roles:view")
            ->name('tenant.accounts.roles.show');

        Route::patch('/{role_id}', [RolesAccountController::class, 'update'])
            ->middleware('auth:sanctum', "abilities:account-roles:update")
            ->name('tenant.accounts.roles.update');

        Route::delete('/{role_id}', [RolesAccountController::class, 'destroy'])
            ->middleware('auth:sanctum', "abilities:account-roles:delete")
            ->name('tenant.accounts.roles.destroy');

        Route::post('/{role_id}/restore', [RolesAccountController::class, 'restore'])
            ->middleware('auth:sanctum', "abilities:account-roles:create,account-roles:update")
            ->name('tenant.accounts.roles.restore');

        Route::delete('/{role_id}/force_delete', [RolesAccountController::class, 'forceDestroy'])
            ->middleware('auth:sanctum', "abilities:account-roles:delete")
            ->name('tenant.accounts.roles.force_destroy');
    });


