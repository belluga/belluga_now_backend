<?php

use App\Http\Api\v1\Controllers\TenantUserController;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AuthControllerTenant;
use App\Http\Api\v1\Controllers\DomainController;
use App\Http\Api\v1\Controllers\AccountController;
use App\Models\Tenants\Account;
use App\Http\Api\v1\Controllers\RolesController;



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
            ->middleware(["tenant"])
            ->group(function () {
                Route::middleware(['ability:'.Account::canManagePermissions()])
                    ->group(function () {
                        Route::post('/', [AccountController::class, 'store'])
                            ->name('tenant.accounts.create');
                    });

                Route::get('/', [AccountController::class, 'index'])
                    ->name('tenant.accounts.list');

                Route::get('/{account_slug}', [AccountController::class, 'show'])
                    ->name('tenant.accounts.show');

                Route::patch('/{account_slug}', [AccountController::class, 'update'])
                    ->name('tenant.accounts.update');

                Route::delete('/{account_slug}', [AccountController::class, 'destroy'])
                    ->name('tenant.accounts.destroy');

                Route::post('/{account_slug}/restore', [AccountController::class, 'restore'])
                    ->name('tenant.accounts.restore');

                Route::post('/{account_slug}/force_delete', [AccountController::class, 'forceDestroy'])
                    ->name('tenant.accounts.force_destroy');;

                Route::get('/{account_slug}/roles', [RolesController::class, 'index'])
                    ->name('tenant.accounts.roles.list');

                Route::post('/{account_slug}/roles', [RolesController::class, 'store'])
                    ->name('tenant.accounts.roles.create');

                Route::get('/{account_slug}/roles/{role_id}', [RolesController::class, 'show'])
                    ->name('tenant.accounts.roles.show');

                Route::patch('/{account_slug}/roles/{role_id}', [RolesController::class, 'update'])
                    ->name('tenant.accounts.roles.update');

                Route::delete('/{account_slug}/roles/{role_id}', [RolesController::class, 'destroy'])
                    ->name('tenant.accounts.roles.destroy');

                Route::post('/{account_slug}/roles/{role_id}/restore', [RolesController::class, 'restore'])
                    ->name('tenant.accounts.roles.restore');
                Route::delete('/{account_slug}/roles/{role_id}/force_delete', [RolesController::class, 'forceDestroy'])
                    ->name('tenant.accounts.roles.force_destroy');
        });
});
