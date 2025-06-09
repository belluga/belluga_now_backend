<?php

use App\Http\Api\v1\Controllers\TenantUserController;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AuthControllerTenant;
use App\Http\Api\v1\Controllers\DomainController;
use App\Http\Api\v1\Controllers\AccountController;



Route::prefix('auth')
    ->group(function () {
    Route::post('/login', [AuthControllerTenant::class, 'login'])
        ->name('tenant.auth.login');

    Route::middleware(['auth:sanctum'])
        ->group(function () {
            Route::post('/logout', [AuthControllerTenant::class, 'logout'])
                ->name('tenant.auth.logout');

            Route::post('/refresh', [AuthControllerTenant::class, 'refresh'])
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

                Route::get('/', [TenantUserController::class, 'index'])
                ->name('tenant.users.index');

                Route::post('/', [TenantUserController::class, 'store'])
                    ->name('tenant.users.store');

                Route::get('/{user_id}', [TenantUserController::class, 'show'])
                    ->name('tenant.users.show');

                Route::patch('/{user_id}', [TenantUserController::class, 'update'])
                    ->name('tenant.users.update');

                Route::delete('/{user_id}', [TenantUserController::class, 'destroy'])
                    ->name('tenant.users.destroy');

                Route::delete('/{user_id}/force_delete', [TenantUserController::class, 'forceDestroy'])
                    ->middleware('abilities:landlord-users:write')
                    ->name('tenant.users.force_destroy');

                Route::post('/{user_id}/restore', [TenantUserController::class, 'restore'])
                    ->middleware('abilities:landlord-users:write')
                    ->name('tenant.users.restore');

                // Perfil do usuário atual
                Route::get('/profile', [TenantUserController::class, 'profile'])
                    ->name('tenant.users.profile');

                Route::put('/profile', [TenantUserController::class, 'updateProfile'])
                    ->name('tenant.users.profile.update');

                // Alterar senha
                Route::put('/{id}/password', [TenantUserController::class, 'updatePassword'])
                    ->name('tenant.users.password.update');

                // Ativar/desativar usuário
                Route::patch('/{id}/toggle-active', [TenantUserController::class, 'toggleActive'])
                    ->name('tenant.users.toggle-active');
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
