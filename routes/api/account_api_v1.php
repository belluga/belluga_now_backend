<?php

use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\RolesAccountController;
use App\Enums\PermissionsActions;

Route::prefix("roles")
    ->middleware(['auth:sanctum','ability:role:'.PermissionsActions::CREATE->value])
    ->group(function () {
        Route::post('/', [RolesAccountController::class, 'store'])
            ->name('account.roles.add');
    });
