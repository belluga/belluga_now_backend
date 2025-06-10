<?php

use App\Http\Api\v1\Controllers\AuthControllerTenant;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\RolesAccountController;
use App\Enums\PermissionsActions;
use App\Http\Api\v1\Controllers\TenantUserController;

Route::prefix("roles")
    ->middleware(['auth:sanctum','ability:role:'.PermissionsActions::CREATE->value])
    ->group(function () {
        Route::post('/', [RolesAccountController::class, 'store'])
            ->name('account.roles.add');
    });

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
            ->name('tenant.users.index');

        Route::post('/', [TenantUserController::class, 'store'])
            ->name('tenant.users.store');

        Route::get('/{user_id}', [TenantUserController::class, 'show'])
            ->name('tenant.users.show');

        Route::patch('/{user_id}', [TenantUserController::class, 'update'])
            ->name('tenant.users.update');

        Route::delete('/{user_id}', [TenantUserController::class, 'destroy'])
            ->name('tenant.users.destroy');

        Route::delete('/{user_id}/force_delete', [TenantUserController::class, 'forceDestroy'])
            ->name('tenant.users.force_destroy');

        Route::post('/{user_id}/restore', [TenantUserController::class, 'restore'])
            ->name('tenant.users.restore');

        Route::patch('/{user_id}/emails', [TenantUserController::class, 'addEmails'])
            ->name('tenant.users.add_emails');

        Route::delete('/{user_id}/emails', [TenantUserController::class, 'removeEmails'])
            ->name('tenant.users.remove_emails');

        // Alterar senha
        Route::put('/{id}/password', [TenantUserController::class, 'updatePassword'])
            ->name('tenant.users.password.update');

        // Ativar/desativar usuário
        Route::patch('/{id}/toggle-active', [TenantUserController::class, 'toggleActive'])
            ->name('tenant.users.toggle-active');
    });

Route::prefix("roles")
    ->group(function () {

        Route::get('/', [RolesAccountController::class, 'index'])
            ->name('tenant.accounts.roles.list');

        Route::post('/', [RolesAccountController::class, 'store'])
            ->name('tenant.accounts.roles.create');

        Route::get('/{role_id}', [RolesAccountController::class, 'show'])
            ->name('tenant.accounts.roles.show');

        Route::patch('/{role_id}', [RolesAccountController::class, 'update'])
            ->name('tenant.accounts.roles.update');

        Route::delete('/{role_id}', [RolesAccountController::class, 'destroy'])
            ->name('tenant.accounts.roles.destroy');

        Route::post('/{role_id}/restore', [RolesAccountController::class, 'restore'])
            ->name('tenant.accounts.roles.restore');

        Route::delete('/{role_id}/force_delete', [RolesAccountController::class, 'forceDestroy'])
            ->name('tenant.accounts.roles.force_destroy');
    });


