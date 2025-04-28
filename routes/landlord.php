<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TenantController;

Route::middleware(['auth:landlord'])->group(function () {
    // Rotas para gerenciamento de tenants
    Route::get('/tenants', [TenantController::class, 'listTenants'])
        ->name('tenants.list');

    Route::post('/tenants/{tenantId}/switch', [TenantController::class, 'switchTenant'])
        ->name('tenants.switch');
});
