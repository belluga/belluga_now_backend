<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {

});

Route::middleware('guest')->group(function () {

    Route::get('/', function () {
        return view('welcome');
    });
});
