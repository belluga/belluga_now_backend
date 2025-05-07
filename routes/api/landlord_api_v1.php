<?php

use App\Http\Api\v1\Controllers\AccountController;
use App\Http\Api\v1\Controllers\AuthControllerContract;
use App\Http\Api\v1\Controllers\CategoryController;
use App\Http\Api\v1\Controllers\InitializationController;
use App\Http\Api\v1\Controllers\ModuleController;
use App\Http\Api\v1\Controllers\ModuleItemController;
use App\Http\Api\v1\Controllers\TenantController;
use App\Http\Api\v1\Controllers\LandlordUserController;
use App\Http\Api\v1\Controllers\TenantUserController;
use App\Http\Api\v1\Controllers\TokenController;
use App\Http\Api\v1\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;
use App\Http\Api\v1\Controllers\AuthControllerLandlord;

Route::prefix('initialize')->middleware('guest')->group(function () {
    Route::post('/', [InitializationController::class, 'initialize'])
        ->name('admin.initialize');
});

// Rotas públicas
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthControllerLandlord::class, 'login'])
        ->name('admin.auth.login');
//
    Route::post('/check', [AuthControllerLandlord::class, 'loginByToken'])
        ->middleware('auth:sanctum')
        ->name('admin.auth.check');
//
    Route::post('/logout', [AuthControllerLandlord::class, 'logout'])
        ->middleware('auth:sanctum')
        ->name('admin.auth.logout');
});

//Route::group(['prefix' => 'modules'], function () {
//    Route::get('/', [ModuleController::class, 'index']);
//    Route::post('/', [ModuleController::class, 'store']);
//    Route::get('/field-types', [ModuleController::class, 'getFieldTypes']);
//    Route::get('/{id}', [ModuleController::class, 'show']);
//    Route::put('/{id}', [ModuleController::class, 'update']);
//    Route::delete('/{id}', [ModuleController::class, 'destroy']);
//
//    // Campos de relacionamento
//    Route::post('/{id}/relation-field', [ModuleController::class, 'addRelationField']);
//    Route::delete('/{id}/field', [ModuleController::class, 'removeField']);
//
//    // Rotas para itens de módulo
//    Route::get('/{moduleId}/items', [ModuleItemController::class, 'index']);
//    Route::post('/{moduleId}/items', [ModuleItemController::class, 'store']);
//    Route::get('/{moduleId}/items/{itemId}', [ModuleItemController::class, 'show']);
//    Route::put('/{moduleId}/items/{itemId}', [ModuleItemController::class, 'update']);
//    Route::delete('/{moduleId}/items/{itemId}', [ModuleItemController::class, 'destroy']);
//});

Route::prefix('tenants')->group(function () {
    Route::get('/', [TenantController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:tenants:read')
        ->name('tenants.index');

    Route::post('/', [TenantController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:tenants:write')
        ->name('tenants.store');

    Route::get('/{tenant_slug}', [TenantController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:tenants:read')
        ->name('tenants.show');

    Route::patch('/{tenant_slug}', [TenantController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:tenants:write')
        ->name('tenants.update');

    Route::delete('/{tenant_slug}', [TenantController::class, 'destroy'])
        ->middleware('auth:sanctum', 'abilities:tenants:delete')
        ->name('tenants.destroy');

    Route::post('/{tenant_slug}/restore', [TenantController::class, 'restore'])
        ->middleware('auth:sanctum', 'abilities:tenants:manage')
        ->name('tenants.restore');

    Route::delete('/{tenant_slug}/force_delete', [TenantController::class, 'forceDestroy'])
        ->middleware('auth:sanctum', 'abilities:tenants:delete')
        ->name('tenants.destroy');
});

Route::prefix('users')->group(function () {
    Route::get('/', [LandlordUserController::class, 'index'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:read')
        ->name('users.index');

    Route::post('/', [LandlordUserController::class, 'store'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.store');

    Route::get('/{user_id}', [LandlordUserController::class, 'show'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:read')
        ->name('users.show');

    Route::patch('/{user_id}', [LandlordUserController::class, 'update'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.update');

    Route::delete('/{user_id}/force_delete', [LandlordUserController::class, 'forceDestroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.destroy');

    Route::post('/{user_id}/restore', [LandlordUserController::class, 'restore'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.restore');

    Route::delete('/{user_id}', [LandlordUserController::class, 'destroy'])
        ->middleware('auth:sanctum', 'abilities:landlord-users:write')
        ->name('users.force_destroy');

    Route::post('/{user_id}/tenants', [LandlordUserController::class, 'tenantUserManage'])
        ->middleware('auth:sanctum', 'abilities:tenants:manage')
        ->name('manage.tenants.users.attach');

    Route::delete('/{user_id}/tenants', [LandlordUserController::class, 'tenantUserManage'])
        ->middleware('auth:sanctum', 'abilities:tenants:manage')
        ->name('manage.tenants.users.detach');
//
//    // Perfil do usuário atual
//    Route::get('/profile', [UserController::class, 'profile'])
//        ->name('users.profile');
//
//    Route::put('/profile', [UserController::class, 'updateProfile'])
//        ->name('users.profile.update');
//
//    // Alterar senha
//    Route::put('/{id}/password', [UserController::class, 'updatePassword'])
//        ->name('users.password.update');
//
//    // Ativar/desativar usuário
//    Route::patch('/{id}/toggle-active', [UserController::class, 'toggleActive'])
//        ->name('users.toggle-active');
});

// Rotas de contas/clientes
//Route::prefix('accounts')->group(function () {
//    Route::post('/{account_slug}/token', [TokenController::class, 'createToken'])
//        ->middleware('guest')
//        ->name('account.token');
//
//    // Rotas protegidas por autenticação
//    Route::middleware('auth:sanctum')->group(function () {
//        Route::get('/', [AccountController::class, 'index'])
//            ->name('account.index');
//
//        Route::post('/', [AccountController::class, 'store'])
//            ->name('account.store');
//
//        Route::get('/{id}', [AccountController::class, 'show'])
//            ->name('account.show');
//
//        Route::put('/{id}', [AccountController::class, 'update'])
//            ->name('account.update');
//
//        Route::delete('/{id}', [AccountController::class, 'destroy'])
//            ->name('account.destroy');
//
//        Route::get('/{account_slug}/users', [AccountController::class, 'users'])
//            ->name('account.users');
//
//        Route::put('/{account_slug}/users', [AccountController::class, 'userAttach'])
//            ->name('account.users.attach');
//
//        Route::delete('/{account_slug}/users/{user_id}', [AccountController::class, 'userDetach'])
//            ->name('account.users.detach');
//    });
//});

// Rotas de módulos e itens de módulos
//Route::middleware('auth:sanctum')->group(function () {
//    // Módulos
//    Route::apiResource('modules', ModuleController::class);
//
//    // Configurações avançadas de módulos
//    Route::prefix('modules')->group(function () {
//        Route::put('/{id}/schemas', [ModuleController::class, 'updateSchemas'])
//            ->name('modules.schemas.update');
//
//        Route::put('/{id}/settings', [ModuleController::class, 'updateSettings'])
//            ->name('modules.settings.update');
//
//        Route::put('/{id}/menu', [ModuleController::class, 'updateMenuSettings'])
//            ->name('modules.menu.update');
//    });
//
//    // Itens de módulos (conteúdo dinâmico)
//    Route::apiResource('modules.items', ModuleItemController::class)->shallow();
//
//    // Operações em lote para itens de módulos
//    Route::post('modules/{module}/items/batch', [ModuleItemController::class, 'batchStore'])
//        ->name('modules.items.batch.store');
//
//    Route::put('modules/items/batch', [ModuleItemController::class, 'batchUpdate'])
//        ->name('modules.items.batch.update');
//
//    Route::delete('modules/items/batch', [ModuleItemController::class, 'batchDestroy'])
//        ->name('modules.items.batch.destroy');
//
//    // Outros recursos
//    Route::apiResource('categories', CategoryController::class);
//    Route::apiResource('transactions', TransactionController::class);
//});
