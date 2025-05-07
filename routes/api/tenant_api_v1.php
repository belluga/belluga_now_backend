<?php

use App\Http\Api\v1\Controllers\AccountController;
use App\Http\Api\v1\Controllers\AuthControllerContract;
use App\Http\Api\v1\Controllers\CategoryController;
use App\Http\Api\v1\Controllers\ModuleController;
use App\Http\Api\v1\Controllers\ModuleItemController;
use App\Http\Api\v1\Controllers\TenantUserController;
use App\Http\Api\v1\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AuthControllerTenant;

// Rotas públicas para tenant
Route::prefix('auth')
    ->middleware('tenant')
    ->group(function () {
    Route::post('/login', [AuthControllerTenant::class, 'tenantLogin'])
        ->name('tenant.auth.login');

    Route::post('/logout', [AuthControllerContract::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('tenant.auth.logout');

    Route::post('/refresh', [AuthControllerContract::class, 'refresh'])
        ->middleware('auth:sanctum')
        ->name('tenant.auth.refresh');
});

// Rotas protegidas para o tenant
Route::middleware('auth:sanctum')->group(function () {
    // Rota para verificar autenticação
    Route::get('/check', function () {
        return response()->json(['authenticated' => true]);
    });

    // Usuários do tenant
    Route::prefix('tenant-users')->group(function () {
        Route::get('/', [TenantUserController::class, 'index'])
            ->name('tenant.users.index');

        Route::post('/', [TenantUserController::class, 'store'])
            ->name('tenant.users.store');

        Route::get('/{id}', [TenantUserController::class, 'show'])
            ->name('tenant.users.show');

        Route::put('/{id}', [TenantUserController::class, 'update'])
            ->name('tenant.users.update');

        Route::delete('/{id}', [TenantUserController::class, 'destroy'])
            ->name('tenant.users.destroy');

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

    // Contas (de usuários)
    Route::prefix('accounts')->group(function () {
        Route::get('/', [AccountController::class, 'index'])
            ->name('tenant.accounts.index');

        Route::post('/', [AccountController::class, 'store'])
            ->name('tenant.accounts.store');

        Route::get('/{account_slug}', [AccountController::class, 'show'])
            ->name('tenant.accounts.show');

        Route::put('/{account_slug}', [AccountController::class, 'update'])
            ->name('tenant.accounts.update');

        Route::delete('/{account_slug}', [AccountController::class, 'destroy'])
            ->name('tenant.accounts.destroy');

        // Usuários da conta
        Route::get('/{account_slug}/users', [TenantUserController::class, 'accountUsers'])
            ->name('tenant.accounts.users.index');

        Route::post('/{account_slug}/users', [TenantUserController::class, 'storeForAccount'])
            ->name('tenant.accounts.users.store');
    });

    // Módulos e itens de módulo
    Route::prefix('modules')->group(function () {
        Route::get('/', [ModuleController::class, 'index'])
            ->name('tenant.modules.index');

        Route::post('/', [ModuleController::class, 'store'])
            ->name('tenant.modules.store');

        Route::get('/field-types', [ModuleController::class, 'getFieldTypes'])
            ->name('tenant.modules.field-types');

        Route::get('/{id}', [ModuleController::class, 'show'])
            ->name('tenant.modules.show');

        Route::put('/{id}', [ModuleController::class, 'update'])
            ->name('tenant.modules.update');

        Route::delete('/{id}', [ModuleController::class, 'destroy'])
            ->name('tenant.modules.destroy');

        // Campos de relacionamento
        Route::post('/{id}/relation-field', [ModuleController::class, 'addRelationField'])
            ->name('tenant.modules.relation-field.store');

        Route::delete('/{id}/field', [ModuleController::class, 'removeField'])
            ->name('tenant.modules.field.destroy');

        // Itens de módulo
        Route::get('/{moduleId}/items', [ModuleItemController::class, 'index'])
            ->name('tenant.modules.items.index');

        Route::post('/{moduleId}/items', [ModuleItemController::class, 'store'])
            ->name('tenant.modules.items.store');

        Route::get('/{moduleId}/items/{itemId}', [ModuleItemController::class, 'show'])
            ->name('tenant.modules.items.show');

        Route::put('/{moduleId}/items/{itemId}', [ModuleItemController::class, 'update'])
            ->name('tenant.modules.items.update');

        Route::delete('/{moduleId}/items/{itemId}', [ModuleItemController::class, 'destroy'])
            ->name('tenant.modules.items.destroy');
    });

    // Categorias
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])
            ->name('tenant.categories.index');

        Route::post('/', [CategoryController::class, 'store'])
            ->name('tenant.categories.store');

        Route::get('/{id}', [CategoryController::class, 'show'])
            ->name('tenant.categories.show');

        Route::put('/{id}', [CategoryController::class, 'update'])
            ->name('tenant.categories.update');

        Route::delete('/{id}', [CategoryController::class, 'destroy'])
            ->name('tenant.categories.destroy');
    });

    // Transações
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index'])
            ->name('tenant.transactions.index');

        Route::post('/', [TransactionController::class, 'store'])
            ->name('tenant.transactions.store');

        Route::get('/{id}', [TransactionController::class, 'show'])
            ->name('tenant.transactions.show');

        Route::put('/{id}', [TransactionController::class, 'update'])
            ->name('tenant.transactions.update');

        Route::delete('/{id}', [TransactionController::class, 'destroy'])
            ->name('tenant.transactions.destroy');
    });
});
