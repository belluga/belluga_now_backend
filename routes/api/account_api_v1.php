<?php

use App\Http\Api\v1\Controllers\AuthControllerAccount;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AccountRolesController;
use App\Http\Api\v1\Controllers\AccountUserController;

Route::prefix('auth')
    ->group(function () {
        Route::post('/login', [AuthControllerAccount::class, 'login'])
            ->name('tenant.auth.login');

        Route::post('/logout', [AuthControllerAccount::class, 'logout'])
            ->name('tenant.auth.login');
    });

Route::prefix('users')
    ->group(function () {

        Route::get('/', [AccountUserController::class, 'index'])
            ->middleware('auth:sanctum', "abilities:account-users:view")
            ->name('tenant.users.index');

        Route::post('/', [AccountUserController::class, 'store'])
            ->middleware('auth:sanctum', "abilities:account-users:create")
            ->name('tenant.users.store');

        Route::get('/{user_id}', [AccountUserController::class, 'show'])
            ->middleware('auth:sanctum', "abilities:account-users:view")
            ->name('tenant.users.show');

        Route::patch('/{user_id}', [AccountUserController::class, 'update'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.update');

        Route::delete('/{user_id}', [AccountUserController::class, 'destroy'])
            ->middleware('auth:sanctum', "abilities:account-users:delete")
            ->name('tenant.users.destroy');

        Route::delete('/{user_id}/force_delete', [AccountUserController::class, 'forceDestroy'])
            ->middleware('auth:sanctum', "abilities:account-users:delete")
            ->name('tenant.users.force_destroy');

        Route::post('/{user_id}/restore', [AccountUserController::class, 'restore'])
            ->middleware('auth:sanctum', "abilities:account-users:create,account-users:update")
            ->name('tenant.users.restore');

        Route::patch('/{user_id}/emails', [AccountUserController::class, 'addEmails'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.add_emails');

        Route::delete('/{user_id}/emails', [AccountUserController::class, 'removeEmails'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.remove_emails');

        Route::put('/{id}/password', [AccountUserController::class, 'updatePassword'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.password.update');

        Route::patch('/{id}/toggle-active', [AccountUserController::class, 'toggleActive'])
            ->middleware('auth:sanctum', "abilities:account-users:update")
            ->name('tenant.users.toggle-active');
    });

Route::prefix("roles")
    ->group(function () {

        Route::get('/', [AccountRolesController::class, 'index'])
            ->middleware('auth:sanctum', "abilities:account-roles:view")
            ->name('tenant.accounts.roles.list');

        Route::post('/', [AccountRolesController::class, 'store'])
            ->middleware('auth:sanctum', "abilities:account-roles:create")
            ->name('tenant.accounts.roles.create');

        Route::get('/{role_id}', [AccountRolesController::class, 'show'])
            ->middleware('auth:sanctum', "abilities:account-roles:view")
            ->name('tenant.accounts.roles.show');

        Route::patch('/{role_id}', [AccountRolesController::class, 'update'])
            ->middleware('auth:sanctum', "abilities:account-roles:update")
            ->name('tenant.accounts.roles.update');

        Route::delete('/{role_id}', [AccountRolesController::class, 'destroy'])
            ->middleware('auth:sanctum', "abilities:account-roles:delete")
            ->name('tenant.accounts.roles.destroy');

        Route::post('/{role_id}/restore', [AccountRolesController::class, 'restore'])
            ->middleware('auth:sanctum', "abilities:account-roles:create,account-roles:update")
            ->name('tenant.accounts.roles.restore');

        Route::delete('/{role_id}/force_delete', [AccountRolesController::class, 'forceDestroy'])
            ->middleware('auth:sanctum', "abilities:account-roles:delete")
            ->name('tenant.accounts.roles.force_destroy');
    });


