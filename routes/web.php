<?php

use App\Http\Controllers\ProbeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    
    Route::get('/', function () {
        return view('welcome');
    });
});
