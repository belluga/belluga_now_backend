<?php

use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AccountRolesTemplatesController;
use App\Http\Api\v1\Controllers\AccountUserController;

Route::middleware('auth:sanctum')
    ->group(function (){

        Route::prefix('users')
            ->group(function () {

                Route::get('/', [AccountUserController::class, 'index'])
                    ->middleware('account', "abilities:account-users:view");

                Route::post('/', [AccountUserController::class, 'store'])
                    ->middleware('account', "abilities:account-users:create");

                Route::get('/{user_id}', [AccountUserController::class, 'show'])
                    ->middleware('account', "abilities:account-users:view");

                Route::patch('/{user_id}', [AccountUserController::class, 'update'])
                    ->middleware('account', "abilities:account-users:update");

                Route::delete('/{user_id}', [AccountUserController::class, 'destroy'])
                    ->middleware('account', "abilities:account-users:delete");
            });

        Route::prefix("roles")
            ->group(function () {

                Route::get('/', [AccountRolesTemplatesController::class, 'index'])
                    ->middleware('account', "abilities:account-roles:view");

                Route::post('/', [AccountRolesTemplatesController::class, 'store'])
                    ->middleware('account', "abilities:account-roles:create");

                Route::get('/{role_id}', [AccountRolesTemplatesController::class, 'show'])
                    ->middleware('account', "abilities:account-roles:view");

                Route::patch('/{role_id}', [AccountRolesTemplatesController::class, 'update'])
                    ->middleware('account', "abilities:account-roles:update");

                Route::delete('/{role_id}', [AccountRolesTemplatesController::class, 'destroy'])
                    ->middleware('account', "abilities:account-roles:delete");

                Route::post('/{role_id}/restore', [AccountRolesTemplatesController::class, 'restore'])
                    ->middleware('account', "abilities:account-roles:create,account-roles:update");

                Route::delete('/{role_id}/force_delete', [AccountRolesTemplatesController::class, 'forceDestroy'])
                    ->middleware('account', "abilities:account-roles:delete");
            });
    });


