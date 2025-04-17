<?php

use App\Http\Api\v1\Controllers\AccountController;
use App\Http\Api\v1\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('initialize')->middleware('guest')->group(function () {
    Route::post('/', [AuthController::class , 'initialize'])
        ->name('initialize');
});

Route::prefix('users')->middleware('auth:sanctum')->group(function () {
    Route::post('/', [AuthController::class , 'register'])
        ->name('users.create');
});

Route::prefix('accounts')->group(function () {
    Route::post('/{account_slug}/token', [AccountController::class, 'createToken'])
        ->middleware('guest')
        ->name('account.token');

    Route::get('/{account_slug}/users', [AccountController::class , 'users'])
        ->middleware('auth:sanctum')
        ->name('account.users');

    Route::get('/', [AccountController::class , 'index'])
        ->middleware('auth:sanctum')
        ->name('account.list');

    Route::post('/', [AccountController::class , 'store'])
        ->middleware('auth:sanctum')
        ->name('account.create');
});
