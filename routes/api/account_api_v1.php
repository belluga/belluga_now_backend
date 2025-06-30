<?php

use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AccountRolesTemplatesController;
use App\Http\Api\v1\Controllers\AccountUserController;

Route::middleware('auth:sanctum')
    ->group(function (){

        Route::prefix('users')
            ->group(function () {

                Route::get('/', [AccountUserController::class, 'index'])
                    ->middleware('account', "abilities:account-users:view")
                    ->name('tenant.users.index');

                Route::post('/', [AccountUserController::class, 'store'])
                    ->middleware('account', "abilities:account-users:create")
                    ->name('tenant.users.store');

                Route::get('/{user_id}', [AccountUserController::class, 'show'])
                    ->middleware('account', "abilities:account-users:view")
                    ->name('tenant.users.show');

                Route::patch('/{user_id}', [AccountUserController::class, 'update'])
                    ->middleware('account', "abilities:account-users:update")
                    ->name('tenant.users.update');

                Route::delete('/{user_id}', [AccountUserController::class, 'destroy'])
                    ->middleware('account', "abilities:account-users:delete")
                    ->name('tenant.users.destroy');

                Route::delete('/{user_id}/force_delete', [AccountUserController::class, 'forceDestroy'])
                    ->middleware('account', "abilities:account-users:delete")
                    ->name('tenant.users.force_destroy');

                Route::post('/{user_id}/restore', [AccountUserController::class, 'restore'])
                    ->middleware('account', "abilities:account-users:create,account-users:update,account-users:delete")
                    ->name('tenant.users.restore');
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


