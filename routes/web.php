<?php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AccountController;

Route::middleware(['auth'])->group(function () {
    // Rotas para gerenciamento de contas
    Route::get('/accounts', [AccountController::class, 'listAccounts'])
        ->name('accounts.list');
        
    Route::post('/accounts/{accountId}/switch', [AccountController::class, 'switchAccount'])
        ->name('accounts.switch');
});
use App\Http\Controllers\ProbeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    
    Route::get('/', function () {
        return view('welcome');
    });
});
