<?php

use App\Http\Api\v1\Controllers\AccountController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // Rotas para gerenciamento de contas
    Route::get('/accounts', [AccountController::class, 'listAccounts'])
        ->name('accounts.list');

    Route::post('/accounts/{accountId}/switch', [AccountController::class, 'switchAccount'])
        ->name('accounts.switch');
});

Route::middleware('guest')->group(function () {

    Route::get('/', function () {
        return view('welcome');
    });
});
