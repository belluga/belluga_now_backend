<?php

use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\RolesController;
use App\Enums\PermissionsActions;

Route::prefix("roles")
    ->middleware(['auth:sanctum','ability:role:'.PermissionsActions::CREATE->value])
    ->group(function () {
        Route::post('/', [RolesController::class, 'store'])
            ->name('account.roles.add');
    });
