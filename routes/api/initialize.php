<?php

use App\Http\Api\v1\Controllers\InitializationController;
use Illuminate\Support\Facades\Route;

Route::post('/', [InitializationController::class, 'initialize']);

// TODO: Check initialization - To redirect users to Initialization page
Route::get('/', [InitializationController::class, 'isInitialized']);
