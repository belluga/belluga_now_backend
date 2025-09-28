<?php

use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\InitializationController;


Route::post('/', [InitializationController::class, 'initialize']);

//TODO: Check initialization - To redirect users to Initialization page
Route::get('/', [InitializationController::class, 'isInitialized']);
