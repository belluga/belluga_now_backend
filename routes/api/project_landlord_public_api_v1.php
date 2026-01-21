<?php

use App\Http\Api\v1\Controllers\EnvironmentController;
use Illuminate\Support\Facades\Route;

Route::get('/environment', [EnvironmentController::class, 'showEnvironmentData']);
