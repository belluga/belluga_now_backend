<?php

declare(strict_types=1);

use Belluga\Email\Http\Controllers\Tenant\TenantEmailSendController;
use Illuminate\Support\Facades\Route;

Route::post('/email/send', TenantEmailSendController::class);
