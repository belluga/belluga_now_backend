<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BrandingController;

Route::middleware(['auth'])->group(function () {

});

Route::middleware('guest')->group(function () {
    Route::get('/', function () {
        return view('welcome');
    });
});

Route::middleware('tenant-maybe')->group(function () {
    Route::get('/manifest.json', [BrandingController::class, 'getManifest']);
    Route::get('/favicon.ico', [BrandingController::class, 'getFavicon']);
});
