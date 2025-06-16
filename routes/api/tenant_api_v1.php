<?php

use App\Http\Api\v1\Controllers\AccountUserController;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AuthControllerAccount;
use App\Http\Api\v1\Controllers\DomainController;
use App\Http\Api\v1\Controllers\AccountController;
use App\Http\Api\v1\Controllers\TenantController;
use App\Http\Api\v1\Controllers\LandlordUserController;



Route::prefix('auth')
    ->group(function () {
    Route::post('/login', [AuthControllerAccount::class, 'login'])
        ->name('tenant.auth.login');

    Route::middleware(['auth:sanctum'])
        ->group(function () {
            Route::post('/logout', [AuthControllerAccount::class, 'logout'])
                ->name('tenant.auth.logout');

            Route::post('/refresh', [AuthControllerAccount::class, 'refresh'])
                ->name('tenant.auth.refresh');
        });
});

Route::prefix('domains')
    ->group(function (){
        Route::post('/', [DomainController::class, 'store'])
            ->name('tenant.domains.add');

        Route::delete('/{domain_id}', [DomainController::class, 'destroy'])
            ->name('tenant.domains.destroy');

        Route::post('/{domain_id}/restore', [DomainController::class, 'restore'])
            ->name('tenant.domains.restore');

        Route::delete('/{domain_id}/force-delete', [DomainController::class, 'forceDestroy'])
            ->name('tenant.domains.force_destroy');
});

// Rotas protegidas para o tenant
Route::middleware('auth:sanctum')
    ->group(function () {
    // Rota para verificar autenticação
        Route::get('/check', function () {
            return response()->json(['authenticated' => true]);
        });

        Route::prefix('users')
            ->group(function () {

                Route::post('/', [TenantController::class, 'tenantUserManage'])
                    ->middleware('auth:sanctum', 'abilities:tenant-users:create,tenant-users:update')
                    ->name('manage.tenants.users.attach');

                Route::delete('/', [TenantController::class, 'tenantUserManage'])
                    ->middleware('auth:sanctum', 'abilities:tenant-users:delete')
                    ->name('manage.tenants.users.detach');

                Route::get('/{user_id}', [LandlordUserController::class, 'show'])
                    ->middleware('auth:sanctum', 'abilities:tenant-users:view')
                    ->name('tenant.users.show');

            });

        Route::prefix('accounts')
            ->group(function () {
                Route::get('/', [AccountController::class, 'index'])
                    ->name('tenant.accounts.list');

                Route::post('/', [AccountController::class, 'store'])
                    ->name('tenant.accounts.create');

                Route::prefix('{account_slug}')
                    ->group(function () {
                        Route::get('/', [AccountController::class, 'show'])
                            ->name('tenant.accounts.show');

                        Route::patch('/', [AccountController::class, 'update'])
                            ->name('tenant.accounts.update');

                        Route::delete('/', [AccountController::class, 'destroy'])
                            ->name('tenant.accounts.destroy');

                        Route::post('/restore', [AccountController::class, 'restore'])
                            ->name('tenant.accounts.restore');

                        Route::post('/force_delete', [AccountController::class, 'forceDestroy'])
                            ->name('tenant.accounts.force_destroy');
                    });
            });


});
