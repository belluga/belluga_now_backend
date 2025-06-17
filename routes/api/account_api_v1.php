<?php

use App\Http\Api\v1\Controllers\AuthControllerAccount;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AccountRolesTemplatesController;
use App\Http\Api\v1\Controllers\AccountUserController;

Route::prefix('auth')
    ->group(function () {
        Route::post('/login', [AuthControllerAccount::class, 'login'])
            ->name('tenant.auth.login');

        Route::post('/logout', [AuthControllerAccount::class, 'logout'])
            ->middleware('auth:sanctum')
            ->name('tenant.auth.login');
    });

Route::middleware('auth:sanctum')
    ->group(function (){
        Route::prefix('users')
            ->group(function () {

                //TODO: Should only show users that have access to the current account.
                Route::get('/', [AccountUserController::class, 'index'])
                    ->middleware('account', "abilities:account-users:view")
                    ->name('tenant.users.index');

                //TODO: Should attach if exists and create and attach if don't exists.
                Route::post('/', [AccountUserController::class, 'store'])
                    ->middleware('account', "abilities:account-users:create")
                    ->name('tenant.users.store');

                //TODO: Should only show users that have access to the current account.
                Route::get('/{user_id}', [AccountUserController::class, 'show'])
                    ->middleware('account', "abilities:account-users:view")
                    ->name('tenant.users.show');

                Route::patch('/{user_id}', [AccountUserController::class, 'update'])
                    ->middleware('account', "abilities:account-users:update")
                    ->name('tenant.users.update');

                //TODO: If the user have access to many accounts, just detach from the current account. Otherwise, delete.
                Route::delete('/{user_id}', [AccountUserController::class, 'destroy'])
                    ->middleware('account', "abilities:account-users:delete")
                    ->name('tenant.users.destroy');

                Route::delete('/{user_id}/force_delete', [AccountUserController::class, 'forceDestroy'])
                    ->middleware('account', "abilities:account-users:delete")
                    ->name('tenant.users.force_destroy');

                Route::post('/{user_id}/restore', [AccountUserController::class, 'restore'])
                    ->middleware('account', "abilities:account-users:create,account-users:update,account-users:delete")
                    ->name('tenant.users.restore');

                Route::patch('/{user_id}/emails', [AccountUserController::class, 'addEmails'])
                    ->middleware('account', "abilities:account-users:update")
                    ->name('tenant.users.add_emails');

                Route::delete('/{user_id}/emails', [AccountUserController::class, 'removeEmails'])
                    ->middleware('account', "abilities:account-users:update")
                    ->name('tenant.users.remove_emails');

                Route::put('/{id}/password', [AccountUserController::class, 'updatePassword'])
                    ->middleware('account', "abilities:account-users:update,profile:update")
                    ->name('tenant.users.password.update');

            });

        Route::prefix("roles")
            ->group(function () {

                Route::get('/', [AccountRolesTemplatesController::class, 'index'])
                    ->middleware('account', "abilities:account-roles:view")
                    ->name('tenant.accounts.roles.list');

                Route::post('/', [AccountRolesTemplatesController::class, 'store'])
                    ->middleware('account', "abilities:account-roles:create")
                    ->name('tenant.accounts.roles.create');

                Route::get('/{role_id}', [AccountRolesTemplatesController::class, 'show'])
                    ->middleware('account', "abilities:account-roles:view")
                    ->name('tenant.accounts.roles.show');

                Route::patch('/{role_id}', [AccountRolesTemplatesController::class, 'update'])
                    ->middleware('account', "abilities:account-roles:update")
                    ->name('tenant.accounts.roles.update');

                Route::delete('/{role_id}', [AccountRolesTemplatesController::class, 'destroy'])
                    ->middleware('account', "abilities:account-roles:delete")
                    ->name('tenant.accounts.roles.destroy');

                Route::post('/{role_id}/restore', [AccountRolesTemplatesController::class, 'restore'])
                    ->middleware('account', "abilities:account-roles:create,account-roles:update")
                    ->name('tenant.accounts.roles.restore');

                Route::delete('/{role_id}/force_delete', [AccountRolesTemplatesController::class, 'forceDestroy'])
                    ->middleware('account', "abilities:account-roles:delete")
                    ->name('tenant.accounts.roles.force_destroy');
            });
    });


