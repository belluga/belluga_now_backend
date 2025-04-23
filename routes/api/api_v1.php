<?php

use App\Http\Api\v1\Controllers\AccountController;
use App\Http\Api\v1\Controllers\AuthController;
use App\Http\Api\v1\Controllers\TokenController;
use App\Http\Api\v1\Controllers\UsersController;
use App\Http\Api\v1\Controllers\CategoryController;
use App\Http\Api\v1\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class , 'login'])
        ->name('auth.login');

    Route::post('/check', [AuthController::class , 'loginByToken'])
        ->middleware('auth:sanctum')
        ->name('auth.check');

    Route::post('/logout', [AuthController::class , 'logout'])
        ->middleware('auth:sanctum')
        ->name('auth.logout');
});

Route::prefix('initialize')->middleware('guest')->group(function () {
    Route::post('/', [AuthController::class , 'initialize'])
        ->name('initialize');
});

Route::prefix('users')->middleware('auth:sanctum')->group(function () {
    Route::post('/', [AuthController::class , 'register'])
        ->name('users.create');

    Route::get('/{user_id}/accounts', [UsersController::class , 'accounts'])
        ->name('users.accounts');
});

Route::prefix('accounts')->group(function () {
    Route::post('/{account_slug}/token', [TokenController::class, 'createToken'])
        ->middleware('guest')
        ->name('account.token');

    Route::get('/{account_slug}/users', [AccountController::class , 'users'])
        ->middleware('auth:sanctum')
        ->name('account.users');

    Route::put('/{account_slug}/users', [AccountController::class , 'userAttach'])
        ->middleware('auth:sanctum')
        ->name('account.users.attach');

    Route::get('/', [AccountController::class , 'index'])
        ->middleware('auth:sanctum')
        ->name('account.list');

    Route::post('/', [AccountController::class , 'store'])
        ->middleware('auth:sanctum')
        ->name('account.create');
});

Route::group(['middleware' => 'auth:sanctum'], function(){
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('transactions', TransactionController::class);
});
